<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dungeon;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

final class DungeonFlowTest extends ApiTestCase
{
    private const string BLIGHT_HOLLOW = 'dungeon.stretch1.blight_hollow';
    private const string BLIGHTLING_WATCH = 'quest.stretch1.blightling_watch';

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function enter(string $characterId, string $dungeonId = self::BLIGHT_HOLLOW, array $headers = []): array
    {
        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/dungeons/{$dungeonId}/enter",
            server: ['CONTENT_TYPE' => 'application/json', ...$headers],
            content: '{}',
        );

        return $this->decode();
    }

    /**
     * Grants one dungeon key the way a player actually would: winning the
     * kill quest that rewards one. This exercises the real Quest -> Dungeon
     * composition ("keys looted from kill quests") rather than a fixture
     * shortcut, and the quest's own requiredLevel (1) means it needs no
     * levelling first.
     */
    private function earnAKey(string $characterId): void
    {
        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/quests/" . self::BLIGHTLING_WATCH . '/accept',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE quest_run SET completes_at = now() - interval '1 second' "
            . 'WHERE character_id = :character AND quest_id = :quest',
            ['character' => $characterId, 'quest' => self::BLIGHTLING_WATCH],
        );

        $claimed = $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/quests/" . self::BLIGHTLING_WATCH . '/claim',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $body = $this->decode();
        self::assertSame('claimed', $body['body']['data']['quest']['status'], 'Fixture assumes the quest is won.');
    }

    /**
     * Levels the character up and spends every resulting point, split evenly
     * across Constitution and Strength. An elite-tier stage (the dungeon's
     * final one reuses encounter.stretch1.stalker) is not guaranteed
     * winnable by an unallocated character the way the first patrol is —
     * only patrol-tier content carries that guarantee — so a dungeon-clear
     * test needs a built character, the same as a real player would have by
     * the time they reach one.
     */
    private function levelAndBuild(string $characterId, int $level): void
    {
        $this->levelTo($characterId, $level);

        $current = $this->getJson('/api/v1/characters/' . $characterId)['body']['data']['character'];
        $points = (int) $current['unspent_points'];

        $this->postJson('/api/v1/characters/' . $characterId . '/attributes', [
            'allocation' => ['CON' => intdiv($points, 2), 'STR' => $points - intdiv($points, 2)],
        ]);
    }

    public function testEnteringWithoutAKeyIsRejected(): void
    {
        $this->registerAndLogin('nokey@example.com');
        $character = $this->createCharacter('Nokey');
        $this->levelTo($character['id'], 4);

        $response = $this->enter($character['id']);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_MATERIAL', $response['body']['error']['code']);
    }

    public function testLevelRequirementIsEnforcedEvenWhenAKeyIsHeld(): void
    {
        $this->registerAndLogin('lowlevel@example.com');
        $character = $this->createCharacter('Lowlevel');
        $this->earnAKey($character['id']);

        $response = $this->enter($character['id']);

        self::assertSame(422, $response['status']);
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame(4, $response['body']['error']['details']['required_level']);
    }

    /**
     * A design requirement, not merely a contract, mirroring
     * EncounterFlowTest::testFreshCharacterCanWinTheFirstPatrol(): a
     * character at the dungeon's own required level must be able to clear
     * it, or the content is mistuned.
     */
    public function testEnteringConsumesTheKeyAndClearsTheDungeon(): void
    {
        $this->registerAndLogin('clear@example.com');
        $character = $this->createCharacter('Clearer');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $response = $this->enter($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        $run = $response['body']['data']['run'];

        self::assertTrue($run['cleared']);
        self::assertCount(3, $run['stages'], 'All three stages must be fought on a full clear.');

        foreach ($run['stages'] as $stage) {
            self::assertSame('victory', $stage['outcome']);
        }

        self::assertGreaterThan(0, $run['rewards']['experience']);
        self::assertGreaterThan(0, $run['rewards']['gold']);

        // The key is gone: entering again without earning another must fail.
        $second = $this->enter($character['id']);
        self::assertSame('INSUFFICIENT_MATERIAL', $second['body']['error']['code']);
    }

    public function testUnknownDungeonIsNotFound(): void
    {
        $this->registerAndLogin('unknowndungeon@example.com');
        $character = $this->createCharacter('Unknowndungeon');

        self::assertSame(404, $this->enter($character['id'], 'dungeon.does.not.exist')['status']);
    }

    public function testEnteringAnotherAccountsCharacterIsNotFound(): void
    {
        $this->registerAndLogin('victim@example.com');
        $victimId = $this->createCharacter('Victim')['id'];

        $this->registerAndLogin('thief@example.com');

        self::assertSame(404, $this->enter($victimId)['status']);
    }

    public function testReplayingAnIdempotencyKeyConsumesOnlyOneKey(): void
    {
        $this->registerAndLogin('idem@example.com');
        $character = $this->createCharacter('Idempotent');
        $this->earnAKey($character['id']);
        $this->levelTo($character['id'], 4);

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'enter-once'];

        $first = $this->enter($character['id'], self::BLIGHT_HOLLOW, $headers);
        $second = $this->enter($character['id'], self::BLIGHT_HOLLOW, $headers);

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame(
            $first['body']['data']['run']['id'],
            $second['body']['data']['run']['id'],
            'A replay must return the original run, not fight the dungeon again.',
        );
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
    }

    public function testEnteringRecordsAnUnpublishedOutboxMessage(): void
    {
        $this->registerAndLogin('outbox@example.com');
        $character = $this->createCharacter('Outboxer');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $this->enter($character['id']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        // Two events by now: the quest claim from earnAKey() and this entry.
        $rows = $connection->fetchAllAssociative(
            "SELECT event_type, payload FROM outbox WHERE event_type = 'dungeon.finished'",
        );

        self::assertCount(1, $rows);

        $payload = json_decode((string) $rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame($character['id'], $payload['characterId']);
        self::assertSame(self::BLIGHT_HOLLOW, $payload['dungeonId']);
        self::assertTrue($payload['cleared']);
        self::assertSame(3, $payload['stagesCleared']);
    }

    public function testDungeonListReportsUnlockAndKeysHeld(): void
    {
        $this->registerAndLogin('list@example.com');
        $character = $this->createCharacter('Lister');

        $before = $this->getJson('/api/v1/characters/' . $character['id'] . '/dungeons');
        self::assertSame(200, $before['status']);

        $entry = $before['body']['data']['dungeons'][0];
        self::assertSame(self::BLIGHT_HOLLOW, $entry['id']);
        self::assertFalse($entry['unlocked'], 'A level 1 character has not unlocked the level 4 dungeon.');
        self::assertSame(0, $entry['keys_held']);

        $this->earnAKey($character['id']);

        $afterKey = $this->getJson('/api/v1/characters/' . $character['id'] . '/dungeons');
        self::assertSame(1, $afterKey['body']['data']['dungeons'][0]['keys_held']);
    }
}
