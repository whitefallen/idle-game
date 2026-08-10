<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quest;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

final class QuestFlowTest extends ApiTestCase
{
    private const string BLIGHTLING_WATCH = 'quest.stretch1.blightling_watch';
    private const string STALKER_HUNT = 'quest.stretch1.stalker_hunt';

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function accept(string $characterId, string $questId = self::BLIGHTLING_WATCH, array $headers = []): array
    {
        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/quests/{$questId}/accept",
            server: ['CONTENT_TYPE' => 'application/json', ...$headers],
            content: '{}',
        );

        return $this->decode();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function claim(string $characterId, string $questId = self::BLIGHTLING_WATCH, array $headers = []): array
    {
        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/quests/{$questId}/claim",
            server: ['CONTENT_TYPE' => 'application/json', ...$headers],
            content: '{}',
        );

        return $this->decode();
    }

    /**
     * Written as SQL for the same reason ApiTestCase::clearActivityGate() is:
     * there is no domain operation that rewinds a timer, and adding one
     * purely for tests would put a hole in the invariant that production
     * code could reach.
     */
    private function makeClaimable(string $characterId, string $questId = self::BLIGHTLING_WATCH): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            "UPDATE quest_run SET completes_at = now() - interval '1 second' "
            . 'WHERE character_id = :character AND quest_id = :quest',
            ['character' => $characterId, 'quest' => $questId],
        );
    }

    public function testAcceptingAQuestSnapshotsAndStartsATimer(): void
    {
        $this->registerAndLogin('accept@example.com');
        $character = $this->createCharacter('Accepter');

        $response = $this->accept($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame('active', $response['body']['data']['status']);
        self::assertNotSame('', $response['body']['data']['completes_at']);
    }

    public function testClaimingBeforeTheTimerElapsesIsRejected(): void
    {
        $this->registerAndLogin('early@example.com');
        $character = $this->createCharacter('Early');

        $this->accept($character['id']);
        $response = $this->claim($character['id']);

        self::assertSame(409, $response['status']);
        self::assertSame('CONFLICT', $response['body']['error']['code']);
    }

    public function testClaimingAnUnacceptedQuestIsRejected(): void
    {
        $this->registerAndLogin('unaccepted@example.com');
        $character = $this->createCharacter('Unaccepted');

        $response = $this->claim($character['id']);

        self::assertSame(409, $response['status']);
        self::assertSame('CONFLICT', $response['body']['error']['code']);
    }

    /**
     * A design requirement, not merely a contract, mirroring
     * EncounterFlowTest::testFreshCharacterCanWinTheFirstPatrol(): a
     * brand-new character must be able to win the quest content aimed at
     * level 1, or a new player's first quest is unwinnable.
     */
    public function testClaimingAfterTheTimerElapsesGrantsTheFixedRewardOnAWin(): void
    {
        $this->registerAndLogin('claim@example.com');
        $character = $this->createCharacter('Claimer');

        $this->accept($character['id']);
        $this->makeClaimable($character['id']);

        $response = $this->claim($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        $quest = $response['body']['data']['quest'];

        self::assertSame('claimed', $quest['status']);
        self::assertSame('victory', $quest['outcome']);
        self::assertSame(20, $quest['rewards']['experience']);
        self::assertSame(15, $quest['rewards']['gold']);
        self::assertSame(['material.dungeon_key' => 1], $quest['rewards']['materials']);
        self::assertNotEmpty($quest['log']['events']);

        $updated = $response['body']['data']['character'];
        self::assertSame(20, $updated['experience']);
        self::assertSame(15, $updated['gold']);
    }

    public function testReacceptingAnActiveQuestIsRejected(): void
    {
        $this->registerAndLogin('doubleaccept@example.com');
        $character = $this->createCharacter('Doubleaccept');

        $this->accept($character['id']);
        $response = $this->accept($character['id']);

        self::assertSame(409, $response['status']);
        self::assertSame('CONFLICT', $response['body']['error']['code']);
    }

    public function testReacceptingAClaimedQuestIsRejected(): void
    {
        $this->registerAndLogin('doubleclaim@example.com');
        $character = $this->createCharacter('Doubleclaim');

        $this->accept($character['id']);
        $this->makeClaimable($character['id']);
        $this->claim($character['id']);

        $response = $this->accept($character['id']);

        self::assertSame(409, $response['status']);
        self::assertSame('CONFLICT', $response['body']['error']['code']);
    }

    public function testLevelRequirementIsEnforcedWithStructuredDetail(): void
    {
        $this->registerAndLogin('lowlevel@example.com');
        $character = $this->createCharacter('Lowlevel');

        $response = $this->accept($character['id'], self::STALKER_HUNT);

        self::assertSame(422, $response['status']);
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame(4, $response['body']['error']['details']['required_level']);
        self::assertSame(1, $response['body']['error']['details']['character_level']);
    }

    public function testUnknownQuestIsNotFound(): void
    {
        $this->registerAndLogin('unknownquest@example.com');
        $character = $this->createCharacter('Unknownquest');

        self::assertSame(404, $this->accept($character['id'], 'quest.does.not.exist')['status']);
        self::assertSame(404, $this->claim($character['id'], 'quest.does.not.exist')['status']);
    }

    public function testAcceptingAnotherAccountsCharacterIsNotFound(): void
    {
        $this->registerAndLogin('victim@example.com');
        $victimId = $this->createCharacter('Victim')['id'];

        $this->registerAndLogin('thief@example.com');

        self::assertSame(404, $this->accept($victimId)['status']);
    }

    public function testReplayingAnAcceptIdempotencyKeyReturnsTheOriginalResult(): void
    {
        $this->registerAndLogin('idemaccept@example.com');
        $character = $this->createCharacter('Idemaccept');

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'accept-once'];

        $first = $this->accept($character['id'], self::BLIGHTLING_WATCH, $headers);
        $second = $this->accept($character['id'], self::BLIGHTLING_WATCH, $headers);

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame(
            $first['body']['data']['completes_at'],
            $second['body']['data']['completes_at'],
            'A replay must return the original acceptance, not re-freeze the snapshot.',
        );
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
    }

    public function testReplayingAClaimIdempotencyKeyReturnsTheOriginalResult(): void
    {
        $this->registerAndLogin('idemclaim@example.com');
        $character = $this->createCharacter('Idemclaim');

        $this->accept($character['id']);
        $this->makeClaimable($character['id']);

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'claim-once'];

        $first = $this->claim($character['id'], self::BLIGHTLING_WATCH, $headers);
        $second = $this->claim($character['id'], self::BLIGHTLING_WATCH, $headers);

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));

        $current = $this->getJson('/api/v1/characters/' . $character['id']);

        self::assertSame(
            15,
            $current['body']['data']['character']['gold'],
            'Only one claim may have granted its reward.',
        );
    }

    /**
     * The event must exist if and only if the claim committed, so it is
     * asserted against the table rather than by observing a subscriber, the
     * same reasoning as EncounterFlowTest::testResolvingRecordsAnUnpublishedOutboxMessage().
     */
    public function testClaimingRecordsAnUnpublishedOutboxMessage(): void
    {
        $this->registerAndLogin('outbox@example.com');
        $character = $this->createCharacter('Outboxer');

        $this->accept($character['id']);
        $this->makeClaimable($character['id']);
        $this->claim($character['id']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $rows = $connection->fetchAllAssociative(
            'SELECT event_type, payload, published_at FROM outbox ORDER BY occurred_at',
        );

        self::assertCount(1, $rows);
        self::assertSame('quest.completed', $rows[0]['event_type']);
        self::assertNull($rows[0]['published_at']);

        $payload = json_decode((string) $rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame($character['id'], $payload['characterId']);
        self::assertSame(self::BLIGHTLING_WATCH, $payload['questId']);
        self::assertSame('victory', $payload['outcome']);
    }

    public function testQuestListReportsUnlockAndStatus(): void
    {
        $this->registerAndLogin('list@example.com');
        $character = $this->createCharacter('Lister');

        $before = $this->getJson('/api/v1/characters/' . $character['id'] . '/quests');
        self::assertSame(200, $before['status']);

        $byId = [];
        foreach ($before['body']['data']['quests'] as $entry) {
            $byId[$entry['id']] = $entry;
        }

        self::assertTrue($byId[self::BLIGHTLING_WATCH]['unlocked']);
        self::assertSame('available', $byId[self::BLIGHTLING_WATCH]['status']);
        self::assertFalse($byId[self::STALKER_HUNT]['unlocked']);

        $this->accept($character['id']);

        $afterAccept = $this->getJson('/api/v1/characters/' . $character['id'] . '/quests');
        $activeEntry = array_values(array_filter(
            $afterAccept['body']['data']['quests'],
            static fn (array $q): bool => $q['id'] === self::BLIGHTLING_WATCH,
        ))[0];

        self::assertSame('active', $activeEntry['status']);
        self::assertFalse($activeEntry['ready_to_claim']);

        $this->makeClaimable($character['id']);

        $ready = $this->getJson('/api/v1/characters/' . $character['id'] . '/quests');
        $readyEntry = array_values(array_filter(
            $ready['body']['data']['quests'],
            static fn (array $q): bool => $q['id'] === self::BLIGHTLING_WATCH,
        ))[0];

        self::assertTrue($readyEntry['ready_to_claim']);
    }
}
