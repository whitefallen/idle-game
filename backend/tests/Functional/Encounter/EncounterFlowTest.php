<?php

declare(strict_types=1);

namespace App\Tests\Functional\Encounter;

use App\Feature\Character\Domain\Service\VigorRules;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

final class EncounterFlowTest extends ApiTestCase
{
    private const string FIRST_PATROL = 'encounter.stretch1.patrol';

    /**
     * @param array<string, string> $headers Server-style keys, e.g. HTTP_IDEMPOTENCY_KEY.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function fight(string $characterId, string $encounterId = self::FIRST_PATROL, array $headers = []): array
    {
        $this->client->request(
            'POST',
            '/api/v1/encounters',
            server: ['CONTENT_TYPE' => 'application/json', ...$headers],
            content: json_encode(
                ['character_id' => $characterId, 'encounter_id' => $encounterId],
                JSON_THROW_ON_ERROR,
            ),
        );

        return $this->decode();
    }

    /**
     * A design requirement, not merely a contract: a brand-new character with
     * no allocated points must be able to win the first patrol. If this fails,
     * the content is mistuned and a new player's first fight is a loss.
     */
    public function testFreshCharacterCanWinTheFirstPatrol(): void
    {
        $this->registerAndLogin('fighter@example.com');
        $character = $this->createCharacter('Fighter');

        $response = $this->fight($character['id']);

        self::assertSame(201, $response['status'], self::describe($response['body']));
        self::assertSame(
            'victory',
            $response['body']['data']['encounter']['outcome'],
            'A level 1 character must be able to win the first patrol.',
        );
    }

    public function testResolvingAnEncounterSpendsVigorAndGrantsRewards(): void
    {
        $this->registerAndLogin('rewards@example.com');
        $character = $this->createCharacter('Rewarded');

        $response = $this->fight($character['id']);
        $encounter = $response['body']['data']['encounter'];
        $updated = $response['body']['data']['character'];

        self::assertSame(VigorRules::CAP - 10, $updated['vigor']['current']);
        self::assertGreaterThan(0, $encounter['rewards']['experience']);
        self::assertGreaterThan(0, $encounter['rewards']['gold']);
        self::assertSame($encounter['rewards']['gold'], $updated['gold']);
        self::assertSame($encounter['rewards']['experience'], $updated['experience']);
    }

    /**
     * The log travels with the result so the replay can begin without a second
     * request. See docs/api.md section 6.
     */
    public function testResponseCarriesTheCompleteReplayLog(): void
    {
        $this->registerAndLogin('replay@example.com');
        $character = $this->createCharacter('Replayer');

        $encounter = $this->fight($character['id'])['body']['data']['encounter'];
        $log = $encounter['log'];

        self::assertSame(1, $log['logVersion']);
        self::assertSame(CombatEngine::RULESET_VERSION, $log['rulesetVersion']);
        self::assertSame($encounter['seed'], $log['seed'], 'The seed must round-trip as a decimal string.');
        self::assertNotEmpty($log['events']);
        self::assertSame('encounter.end', $log['events'][array_key_last($log['events'])]['t']);

        // Legibility: every action records which battle plan rule fired.
        $matched = array_values(array_filter($log['events'], static fn (array $e): bool => $e['t'] === 'plan.matched'));

        self::assertNotEmpty($matched);
        self::assertArrayHasKey('rule', $matched[0]);
    }

    public function testStoredEncounterCanBeRetrievedAndReplayed(): void
    {
        $this->registerAndLogin('stored@example.com');
        $character = $this->createCharacter('Storedone');

        $created = $this->fight($character['id'])['body']['data']['encounter'];

        $fetched = $this->getJson('/api/v1/encounters/' . $created['id']);

        self::assertSame(200, $fetched['status']);

        // The log survives compression and storage byte-for-byte, which is what
        // makes a stored fight genuinely replayable years later.
        self::assertSame($created['log'], $fetched['body']['data']['encounter']['log']);
        self::assertSame($created['seed'], $fetched['body']['data']['encounter']['seed']);
        self::assertSame($created['ruleset_version'], $fetched['body']['data']['encounter']['ruleset_version']);
    }

    public function testInsufficientVigorIsRejectedWithActionableDetail(): void
    {
        $this->registerAndLogin('drained@example.com');
        $character = $this->createCharacter('Drained');

        // The cap allows twelve patrols at ten Vigor each.
        for ($i = 0; $i < 12; ++$i) {
            self::assertSame(201, $this->fight($character['id'])['status'], 'fight ' . $i);
        }

        $response = $this->fight($character['id']);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_VIGOR', $response['body']['error']['code']);
        self::assertSame(10, $response['body']['error']['details']['required']);
        self::assertSame(0, $response['body']['error']['details']['available']);
    }

    public function testLevelRequirementIsEnforcedWithStructuredDetail(): void
    {
        $this->registerAndLogin('lowlevel@example.com');
        $character = $this->createCharacter('Lowlevel');

        $response = $this->fight($character['id'], 'encounter.stretch1.stalker');

        self::assertSame(422, $response['status']);
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame(4, $response['body']['error']['details']['required_level']);
        self::assertSame(1, $response['body']['error']['details']['character_level']);
    }

    public function testUnknownEncounterIsNotFound(): void
    {
        $this->registerAndLogin('unknownenc@example.com');
        $character = $this->createCharacter('Unknownenc');

        self::assertSame(404, $this->fight($character['id'], 'encounter.does.not.exist')['status']);
    }

    public function testFightingAnotherAccountsCharacterIsNotFound(): void
    {
        $this->registerAndLogin('victim2@example.com');
        $victimId = $this->createCharacter('Victimtwo')['id'];

        $this->registerAndLogin('thief@example.com');

        self::assertSame(404, $this->fight($victimId)['status']);
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    /**
     * A double-tapped button must not cost Vigor twice. This is the case the
     * whole idempotency mechanism exists for.
     */
    public function testReplayingAnIdempotencyKeyReturnsTheOriginalResult(): void
    {
        $this->registerAndLogin('idem@example.com');
        $character = $this->createCharacter('Idempotent');

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'fight-once-abc123'];

        $first = $this->fight($character['id'], self::FIRST_PATROL, $headers);
        $second = $this->fight($character['id'], self::FIRST_PATROL, $headers);

        self::assertSame(201, $first['status']);
        self::assertSame(201, $second['status']);
        self::assertSame(
            $first['body']['data']['encounter']['id'],
            $second['body']['data']['encounter']['id'],
            'A replay must return the original encounter, not fight again.',
        );

        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));

        $current = $this->getJson('/api/v1/characters/' . $character['id']);

        self::assertSame(
            VigorRules::CAP - 10,
            $current['body']['data']['character']['vigor']['current'],
            'Only one encounter may have been charged.',
        );
    }

    public function testSameKeyWithADifferentBodyIsAConflict(): void
    {
        $this->registerAndLogin('idemconflict@example.com');
        $character = $this->createCharacter('Conflicted');

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'reused-key-xyz'];

        $this->fight($character['id'], self::FIRST_PATROL, $headers);
        $conflict = $this->fight($character['id'], 'encounter.stretch1.swarm', $headers);

        self::assertSame(409, $conflict['status']);
        self::assertSame('IDEMPOTENCY_CONFLICT', $conflict['body']['error']['code']);
    }

    // -----------------------------------------------------------------
    // Outbox
    // -----------------------------------------------------------------

    /**
     * The event must exist if and only if the state change committed, so it is
     * asserted against the table rather than by observing a subscriber.
     */
    public function testResolvingRecordsAnUnpublishedOutboxMessage(): void
    {
        $this->registerAndLogin('outbox@example.com');
        $character = $this->createCharacter('Outboxer');

        $encounter = $this->fight($character['id'])['body']['data']['encounter'];

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $rows = $connection->fetchAllAssociative(
            'SELECT event_type, payload, published_at, attempts FROM outbox ORDER BY occurred_at',
        );

        self::assertCount(1, $rows);
        self::assertSame('encounter.resolved', $rows[0]['event_type']);
        self::assertNull($rows[0]['published_at'], 'The relay publishes; the request must not.');
        self::assertSame(0, (int) $rows[0]['attempts']);

        $payload = json_decode((string) $rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame($encounter['id'], $payload['encounterId']);
        self::assertSame($character['id'], $payload['characterId']);
        self::assertSame(self::FIRST_PATROL, $payload['definitionId']);

        // Ids and primitives only — never entities. See ADR-0004.
        foreach ($payload as $value) {
            self::assertTrue(is_scalar($value) || is_array($value), 'Event payloads carry primitives only.');
        }
    }

    public function testFailedResolutionRecordsNoOutboxMessage(): void
    {
        $this->registerAndLogin('nooutbox@example.com');
        $character = $this->createCharacter('Nooutbox');

        $this->fight($character['id'], 'encounter.stretch1.stalker');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM outbox'),
            'A rejected encounter must leave no event claiming it happened.',
        );
    }

    // -----------------------------------------------------------------
    // Discovery and history
    // -----------------------------------------------------------------

    public function testAvailableEncountersReportUnlockAndAffordability(): void
    {
        $this->registerAndLogin('available@example.com');
        $character = $this->createCharacter('Available');

        $response = $this->getJson('/api/v1/characters/' . $character['id'] . '/encounters/available');

        self::assertSame(200, $response['status']);

        $byId = [];
        foreach ($response['body']['data']['encounters'] as $entry) {
            $byId[$entry['id']] = $entry;
        }

        self::assertTrue($byId[self::FIRST_PATROL]['unlocked']);
        self::assertTrue($byId[self::FIRST_PATROL]['affordable']);
        self::assertFalse($byId['encounter.stretch1.stalker']['unlocked']);
        self::assertSame(4, $byId['encounter.stretch1.stalker']['required_level']);
    }

    public function testHistoryReturnsMostRecentFirst(): void
    {
        $this->registerAndLogin('history@example.com');
        $character = $this->createCharacter('Historian');

        $this->fight($character['id']);
        $second = $this->fight($character['id'])['body']['data']['encounter'];

        $response = $this->getJson('/api/v1/characters/' . $character['id'] . '/encounters');

        self::assertSame(200, $response['status']);
        self::assertCount(2, $response['body']['data']['encounters']);
        self::assertSame($second['id'], $response['body']['data']['encounters'][0]['id']);
    }
}
