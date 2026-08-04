<?php

declare(strict_types=1);

namespace App\Tests\Functional\Encounter;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Guards the pessimistic write lock taken when an encounter is resolved.
 *
 * `ResolveEncounterHandler` opens with `findByIdForUpdate`, which issues
 * `SELECT … FOR UPDATE` on the character row and holds it for the transaction.
 * Two things depend on it:
 *
 *  - **Vigor cannot be double-spent.** Without the lock, two concurrent
 *    requests both read the same balance, both pass the affordability check,
 *    and both resolve an encounter — one cost, two sets of rewards.
 *  - **The activity gate is atomic.** The gate is a check-then-act on
 *    `vigor_spent_at`; without the lock, two concurrent requests both read a
 *    gate that is still open.
 *
 * Neither of those is visible in a single-threaded test, and changing
 * `findByIdForUpdate` to `findById` leaves the rest of the suite green. This is
 * the test that does not stay green — the same reasoning behind
 * CombatEnginePurityTest, which exists because purity erodes one convenient
 * change at a time.
 */
final class EncounterLockTest extends ApiTestCase
{
    /**
     * How long the request is allowed to wait for the row before Postgres gives
     * up. Long enough that a slow container does not report a false lock, short
     * enough that the case costs a fraction of a second.
     */
    private const string LOCK_TIMEOUT = '750ms';

    /** Requires level 4; the character in this test is level 1. */
    private const string GATED_ENCOUNTER = 'encounter.stretch1.stalker';

    /**
     * Proves the lock is taken, using a request the handler is going to reject.
     *
     * The eligibility checks run *after* the lock is acquired, so a request that
     * fails them is the cleanest possible probe:
     *
     * | | With the lock | Without it |
     * |---|---|---|
     * | Behaviour | Blocks on the held row lock, then times out | Reads freely, rejects on the level requirement |
     * | Result | A server error | A clean 422 `REQUIREMENT_NOT_MET` |
     *
     * A probe that would *succeed* proves nothing: writing the character's Vigor
     * needs a row lock at commit time regardless, so an unlocked handler would
     * block on the UPDATE instead and the test would pass either way. Choosing a
     * request that never reaches a write is what makes this discriminate.
     */
    public function testResolvingAnEncounterTakesAWriteLockOnTheCharacter(): void
    {
        $this->registerAndLogin('lockguard@example.com');
        $character = $this->createCharacter('LockGuard');

        /** @var Connection $application */
        $application = static::getContainer()->get(Connection::class);

        $holder = $this->secondConnection($application);
        $holder->beginTransaction();

        try {
            $holder->executeQuery(
                'SELECT id FROM game_character WHERE id = :id FOR UPDATE',
                ['id' => $character['id']],
            );

            $this->boundLockWait($application, self::LOCK_TIMEOUT);

            $response = $this->postJson('/api/v1/encounters', [
                'character_id' => $character['id'],
                'encounter_id' => self::GATED_ENCOUNTER,
            ]);
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->restoreLockWait($application);
        }

        self::assertNotSame(
            422,
            $response['status'],
            'The request reached its eligibility checks while another transaction held a write lock on the '
            . 'character, so it cannot have taken one itself. ResolveEncounterHandler must open with '
            . 'findByIdForUpdate — without it, concurrent requests double-spend Vigor and the activity gate '
            . 'stops being atomic.',
        );

        self::assertSame(
            500,
            $response['status'],
            'Expected the blocked lock acquisition to surface as an internal error. '
            . 'Actual: ' . self::describe($response['body']),
        );
    }

    /**
     * The refused request must leave nothing behind — no encounter, no Vigor
     * spent. A lock timeout arrives mid-transaction, so this is really asserting
     * that the transaction rolled back cleanly.
     *
     * Note that this case is **not** the guard on the lock, and passes with or
     * without it: an unlocked handler would block on the UPDATE at commit time
     * instead, and roll back just the same. The case above is the discriminator.
     * Verified by removing `findByIdForUpdate` and re-running — only the case
     * above turns red.
     */
    public function testARequestThatCannotAcquireTheLockChangesNothing(): void
    {
        $this->registerAndLogin('locknochange@example.com');
        $character = $this->createCharacter('LockNoChange');

        /** @var Connection $application */
        $application = static::getContainer()->get(Connection::class);

        $before = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];

        $holder = $this->secondConnection($application);
        $holder->beginTransaction();

        try {
            $holder->executeQuery(
                'SELECT id FROM game_character WHERE id = :id FOR UPDATE',
                ['id' => $character['id']],
            );

            $this->boundLockWait($application, self::LOCK_TIMEOUT);

            $this->postJson('/api/v1/encounters', [
                'character_id' => $character['id'],
                'encounter_id' => 'encounter.stretch1.patrol',
            ]);
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->restoreLockWait($application);
        }

        $after = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];
        $history = $this->getJson('/api/v1/characters/' . $character['id'] . '/encounters');

        self::assertSame($before['vigor']['current'], $after['vigor']['current'], 'Vigor must be untouched.');
        self::assertSame([], $history['body']['data']['encounters'], 'No encounter may be recorded.');
    }

    /**
     * A genuinely separate connection, so the lock it takes is one the
     * application's own connection has to contend with. Reusing the container's
     * connection would make the test lock against itself, which Postgres
     * permits and which would prove nothing.
     */
    private function secondConnection(Connection $application): Connection
    {
        /** @var array{user?: string, dbname?: string} $params */
        $params = $application->getParams();

        return DriverManager::getConnection($params);
    }

    /**
     * Bounds how long a blocked statement waits, at the role level rather than
     * the session level.
     *
     * `SET lock_timeout` applies to one session, and Doctrine closes and
     * reopens its connection between requests, so a session-scoped setting is
     * gone by the time the request that needs it runs — the blocked query then
     * waits forever and the suite hangs instead of failing. A role setting is
     * applied by Postgres to every *new* connection, which is exactly what the
     * request gets.
     */
    private function boundLockWait(Connection $application, string $timeout): void
    {
        /** @var array{user?: string, dbname?: string} $params */
        $params = $application->getParams();

        $application->executeStatement(sprintf(
            'ALTER ROLE %s IN DATABASE %s SET lock_timeout = %s',
            $application->quoteSingleIdentifier((string) ($params['user'] ?? 'emberwatch')),
            $application->quoteSingleIdentifier((string) ($params['dbname'] ?? 'emberwatch_test')),
            $application->quote($timeout),
        ));

        // Force the next statement onto a fresh connection, so the role setting
        // above is actually picked up.
        $application->close();
    }

    private function restoreLockWait(Connection $application): void
    {
        /** @var array{user?: string, dbname?: string} $params */
        $params = $application->getParams();

        $application->executeStatement(sprintf(
            'ALTER ROLE %s IN DATABASE %s RESET lock_timeout',
            $application->quoteSingleIdentifier((string) ($params['user'] ?? 'emberwatch')),
            $application->quoteSingleIdentifier((string) ($params['dbname'] ?? 'emberwatch_test')),
        ));

        $application->close();
    }
}
