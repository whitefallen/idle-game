<?php

declare(strict_types=1);

namespace App\Tests\Functional\Holding;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Holding\Domain\Service\HoldingRules;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The idle layer over HTTP.
 *
 * Accrual is driven by backdating the stored anchors rather than by waiting or
 * by mocking the clock. The server's clock stays real — rule T1 says the server
 * owns it, and a test that replaces it proves nothing about the code that ships.
 */
final class HoldingFlowTest extends ApiTestCase
{
    private const string EMBERASH = 'material.emberash';

    private const string SLAGIRON = 'material.slagiron';

    public function testAFreshHoldingHasTwoIdleSlotsAndNothingPending(): void
    {
        $this->registerAndLogin('holding-fresh@example.test');
        $character = $this->createCharacter('Ashwarden');

        $holding = $this->holdingOf($character['id']);

        self::assertSame(2, $holding['slots_unlocked']);
        self::assertNull($holding['slots'][0]['material_id']);
        self::assertSame(0, $holding['pending']['gold']);
        self::assertSame([], $holding['pending']['materials']);
        self::assertSame(HoldingRules::capSecondsAt(1), $holding['cap_seconds']);
    }

    /**
     * Reading must not write. A GET that provisioned a row would mean every
     * page load of an idle screen is a write, and the anchors would start
     * whenever a player happened to look rather than when they claimed.
     */
    public function testReadingTheHoldingDoesNotCreateOne(): void
    {
        $this->registerAndLogin('holding-read-only@example.test');
        $character = $this->createCharacter('Quiet Warden');

        $this->holdingOf($character['id']);

        self::assertSame(0, $this->countHoldings(), 'A read must not provision a Holding.');
    }

    /**
     * Locked slots are listed too, each carrying the level that unlocks it.
     *
     * The client must never have to invent them: the unlock ladder is a game
     * rule, and a client that synthesises its own placeholders synthesises
     * their requirements too — and gets them wrong.
     */
    public function testEverySlotIsListedIncludingTheOnesNotYetUnlocked(): void
    {
        $this->registerAndLogin('holding-slot-ladder@example.test');
        $character = $this->createCharacter('Slotwatcher');

        $holding = $this->holdingOf($character['id']);

        self::assertCount(HoldingRules::MAX_SLOTS, $holding['slots']);
        self::assertTrue($holding['slots'][1]['unlocked']);
        self::assertFalse($holding['slots'][2]['unlocked']);
        self::assertSame(10, $holding['slots'][2]['unlocks_at_level']);
        self::assertSame(60, $holding['slots'][6]['unlocks_at_level']);
    }

    /**
     * Locked lines are listed, with what unlocks them. A progression axis a
     * player cannot see ahead of is one they cannot plan around.
     */
    public function testLockedProductionLinesAreVisibleWithTheirRequirement(): void
    {
        $this->registerAndLogin('holding-lines@example.test');
        $character = $this->createCharacter('Linewatcher');

        $ordered = $this->holdingOf($character['id'])['lines'];
        $lines = [];

        foreach ($ordered as $line) {
            $lines[$line['material_id']] = $line;
        }

        // Tier order, not id order. Alphabetically the list opens on
        // Cinderglass — a line unlocking at level 45 — and buries the one a new
        // character can actually use.
        self::assertSame(
            [1, 2, 3, 4],
            array_column($ordered, 'tier'),
            'Production lines must be ordered by tier.',
        );

        self::assertTrue($lines[self::EMBERASH]['unlocked']);
        self::assertFalse($lines[self::SLAGIRON]['unlocked']);
        self::assertSame(15, $lines[self::SLAGIRON]['unlock_level']);
        self::assertArrayNotHasKey(
            'material.blightcore',
            $lines,
            'A drop-only material is not a production line and must not be offered as one.',
        );
    }

    public function testAssigningALineAndClaimingItsOutput(): void
    {
        $this->registerAndLogin('holding-claim@example.test');
        $character = $this->createCharacter('Emberkeeper');

        $assigned = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/0', $character['id']),
            ['material_id' => self::EMBERASH],
        );

        self::assertSame(200, $assigned['status'], self::describe($assigned['body']));
        self::assertSame(self::EMBERASH, $assigned['body']['data']['holding']['slots'][0]['material_id']);
        self::assertSame(12, $assigned['body']['data']['holding']['slots'][0]['rate_per_hour']);

        $this->backdate($character['id'], 3 * 3600);

        // What the read endpoint promises and what the claim delivers come from
        // one calculation, and this is the test that keeps them that way.
        $pending = $this->holdingOf($character['id'])['pending'];
        self::assertSame(36, $pending['materials'][self::EMBERASH]);

        $claim = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(200, $claim['status'], self::describe($claim['body']));
        self::assertSame(36, $claim['body']['data']['claimed']['materials'][self::EMBERASH]);
        self::assertSame(
            3 * HoldingRules::tithePerHour(1),
            $claim['body']['data']['claimed']['gold'],
            'The tithe accrues alongside the slots, on its own anchor.',
        );

        $stash = [];

        foreach ($claim['body']['data']['holding']['stash'] as $entry) {
            $stash[$entry['material_id']] = $entry['quantity'];
        }

        self::assertSame(36, $stash[self::EMBERASH], 'A claim must land in the stash, not only in the response.');
    }

    /**
     * The anchors moved, so there is nothing left to claim. This is what makes
     * an impatient player harmless before replay protection is even considered.
     */
    public function testASecondImmediateClaimGrantsNothing(): void
    {
        $this->registerAndLogin('holding-double@example.test');
        $character = $this->createCharacter('Twiceclaimer');

        $this->assign($character['id'], 0, self::EMBERASH);
        $this->backdate($character['id'], 3 * 3600);

        $first = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);
        $second = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(36, $first['body']['data']['claimed']['materials'][self::EMBERASH]);
        self::assertSame(200, $second['status'], 'Claiming an empty Holding is not an error.');
        self::assertSame([], $second['body']['data']['claimed']['materials']);
        self::assertSame(0, $second['body']['data']['claimed']['gold']);
    }

    /**
     * A replayed key returns the original outcome. Without it the second
     * delivery of one intent would report an empty claim for an action that
     * actually granted something.
     */
    public function testAReplayedClaimReturnsTheOriginalResult(): void
    {
        $this->registerAndLogin('holding-idempotent@example.test');
        $character = $this->createCharacter('Steadyhand');

        $this->assign($character['id'], 0, self::EMBERASH);
        $this->backdate($character['id'], 3 * 3600);

        $key = Uuid::v7()->toRfc4122();
        $uri = sprintf('/api/v1/characters/%s/holding/claim', $character['id']);

        $first = $this->postWithKey($uri, [], $key);
        $replay = $this->postWithKey($uri, [], $key);

        self::assertSame(36, $first['body']['data']['claimed']['materials'][self::EMBERASH]);
        self::assertSame(
            $first['body']['data']['claimed'],
            $replay['body']['data']['claimed'],
            'A replayed key must return the original outcome, not a fresh empty claim.',
        );
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame(36, $this->stashOf($character['id'])[self::EMBERASH], 'The replay must not grant twice.');
    }

    /**
     * Production stops at the cap. A player away for a month is paid for the
     * cap and no more — and the time beyond it is gone, not banked for the
     * next claim.
     */
    public function testAbsenceIsCappedAndTheExcessIsNotBanked(): void
    {
        $this->registerAndLogin('holding-cap@example.test');
        $character = $this->createCharacter('Longgone');

        $this->assign($character['id'], 0, self::EMBERASH);
        $this->backdate($character['id'], 30 * 86_400);

        $capped = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(HoldingRules::capSecondsAt(1), $capped['body']['data']['claimed']['elapsed_seconds']);
        self::assertSame(12 * 12, $capped['body']['data']['claimed']['materials'][self::EMBERASH]);

        $again = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(
            [],
            $again['body']['data']['claimed']['materials'],
            'The month beyond the cap must be discarded, not left on the anchor.',
        );
    }

    public function testALineAboveTheCharactersLevelIsRefusedWithItsRequirement(): void
    {
        $this->registerAndLogin('holding-locked-line@example.test');
        $character = $this->createCharacter('Ambitious');

        $refused = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/0', $character['id']),
            ['material_id' => self::SLAGIRON],
        );

        self::assertSame(422, $refused['status'], self::describe($refused['body']));
        self::assertSame('REQUIREMENT_NOT_MET', $refused['body']['error']['code']);
        self::assertSame(15, $refused['body']['error']['details']['required_level']);
    }

    public function testALockedSlotIsRefusedWithTheLevelThatUnlocksIt(): void
    {
        $this->registerAndLogin('holding-locked-slot@example.test');
        $character = $this->createCharacter('Overreacher');

        $refused = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/2', $character['id']),
            ['material_id' => self::EMBERASH],
        );

        self::assertSame(422, $refused['status'], self::describe($refused['body']));
        self::assertSame(10, $refused['body']['error']['details']['unlocks_at_level']);
    }

    public function testADropOnlyMaterialCannotBeProduced(): void
    {
        $this->registerAndLogin('holding-droponly@example.test');
        $character = $this->createCharacter('Coreseeker');

        $refused = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/0', $character['id']),
            ['material_id' => 'material.blightcore'],
        );

        self::assertSame(422, $refused['status'], self::describe($refused['body']));
        self::assertSame('VALIDATION_FAILED', $refused['body']['error']['code']);
    }

    /**
     * Reassignment resets that slot's accrual, and only that slot's — the
     * reason each slot carries its own anchor rather than sharing the row's.
     */
    public function testReassigningOneSlotLeavesTheOtherUntouched(): void
    {
        $this->registerAndLogin('holding-reassign@example.test');
        $character = $this->createCharacter('Secondguess');

        $this->assign($character['id'], 0, self::EMBERASH);
        $this->assign($character['id'], 1, self::EMBERASH);
        $this->backdate($character['id'], 3 * 3600);

        // Clearing slot 0 discards its three hours.
        $this->assign($character['id'], 0, null);

        $claim = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(
            36,
            $claim['body']['data']['claimed']['materials'][self::EMBERASH],
            'One slot\'s three hours survive; the reassigned slot\'s do not.',
        );
    }

    public function testLevellingUnlocksASlotAndItsLines(): void
    {
        $this->registerAndLogin('holding-level@example.test');
        $character = $this->createCharacter('Risenwarden');

        $this->levelTo($character['id'], 20);

        $holding = $this->holdingOf($character['id']);

        self::assertSame(4, $holding['slots_unlocked']);
        self::assertSame(HoldingRules::capSecondsAt(20), $holding['cap_seconds']);

        $assigned = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/3', $character['id']),
            ['material_id' => self::SLAGIRON],
        );

        self::assertSame(200, $assigned['status'], self::describe($assigned['body']));
    }

    /**
     * Another account's character is absent, not forbidden: a 403 confirms the
     * id exists, which turns the endpoint into an enumeration oracle.
     */
    public function testAnotherAccountsHoldingIsNotFound(): void
    {
        $this->registerAndLogin('holding-owner@example.test');
        $character = $this->createCharacter('Someone Elses');

        $this->client->request('POST', '/api/v1/auth/logout');
        $this->registerAndLogin('holding-intruder@example.test');

        $read = $this->getJson(sprintf('/api/v1/characters/%s/holding', $character['id']));
        $claim = $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        self::assertSame(404, $read['status']);
        self::assertSame(404, $claim['status']);
    }

    /**
     * Rule T6: every claim is audited with amount, elapsed time and balance.
     * Economy exploits are found in aggregate data, and that data has to exist
     * before the exploit does.
     */
    public function testEveryClaimIsAuditedAndAnnounced(): void
    {
        $this->registerAndLogin('holding-audit@example.test');
        $character = $this->createCharacter('Accountable');

        $this->assign($character['id'], 0, self::EMBERASH);
        $this->backdate($character['id'], 3 * 3600);
        $this->postJson(sprintf('/api/v1/characters/%s/holding/claim', $character['id']), []);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $audited = $connection->fetchAssociative(
            "SELECT context FROM audit_log WHERE action = 'holding.claimed'",
        );

        self::assertIsArray($audited, 'A claim must leave an audit record.');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $audited['context'], true, 8, JSON_THROW_ON_ERROR);

        // A window rather than an exact figure: the anchor is backdated by
        // three hours from a real clock that keeps running, so the elapsed time
        // is three hours plus however long the test itself took. What matters
        // for T6 is that the number is recorded and is the real interval.
        self::assertGreaterThanOrEqual(3 * 3600, $payload['elapsedSeconds']);
        self::assertLessThan(3 * 3600 + 60, $payload['elapsedSeconds']);
        self::assertSame(36, $payload['materials'][self::EMBERASH]);
        self::assertArrayHasKey('balance', $payload['gold']);

        self::assertSame(
            1,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE event_type = 'holding.claimed'"),
            'The deferred half of the fan-out travels through the outbox.',
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function holdingOf(string $characterId): array
    {
        $response = $this->getJson('/api/v1/characters/' . $characterId . '/holding');

        self::assertSame(200, $response['status'], self::describe($response['body']));

        /** @var array<string, mixed> $holding */
        $holding = $response['body']['data']['holding'];

        return $holding;
    }

    /**
     * @return array<string, int>
     */
    private function stashOf(string $characterId): array
    {
        $stash = [];

        foreach ($this->holdingOf($characterId)['stash'] as $entry) {
            $stash[$entry['material_id']] = $entry['quantity'];
        }

        return $stash;
    }

    private function assign(string $characterId, int $index, ?string $materialId): void
    {
        $response = $this->putJson(
            sprintf('/api/v1/characters/%s/holding/slots/%d', $characterId, $index),
            $materialId === null ? [] : ['material_id' => $materialId],
        );

        self::assertSame(200, $response['status'], self::describe($response['body']));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function putJson(string $uri, array $payload): array
    {
        $this->client->request(
            'PUT',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postWithKey(string $uri, array $payload, string $idempotencyKey): array
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * Moves every anchor of this character's Holding into the past.
     *
     * Written as SQL for the same reason {@see ApiTestCase::clearActivityGate()}
     * is: there is no domain operation that rewinds an anchor, and adding one
     * purely for tests would put a hole in the invariant that production code
     * could reach.
     *
     * Slot order is preserved explicitly. `jsonb_agg` over a set has no
     * inherent order, and a test that silently reordered the slots would make
     * "slot 0" mean whatever the planner felt like that day.
     */
    private function backdate(string $characterId, int $seconds): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                UPDATE holding
                SET last_claimed_at = last_claimed_at - make_interval(secs => :seconds),
                    slots = (
                        SELECT COALESCE(
                            jsonb_agg(
                                slot || jsonb_build_object(
                                    'accruedAt', (slot->>'accruedAt')::bigint - :seconds
                                )
                                ORDER BY ordinality
                            ),
                            '[]'::jsonb
                        )
                        FROM jsonb_array_elements(slots) WITH ORDINALITY AS entries(slot, ordinality)
                    )
                WHERE character_id = :character
                SQL,
            ['seconds' => $seconds, 'character' => $characterId],
        );
    }

    private function countHoldings(): int
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM holding');
    }

    private function levelTo(string $characterId, int $level): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var Character $character */
        $character = $entityManager->find(Character::class, Uuid::fromString($characterId));

        $character->awardExperience(
            ProgressionRules::cumulativeExperienceFor($level),
            EquipmentBonuses::none(),
            new DateTimeImmutable(),
        );

        $entityManager->flush();
        $entityManager->clear();
    }
}
