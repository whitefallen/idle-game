<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dungeon;

use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DungeonFlowTest extends ApiTestCase
{
    /** Repeatable, material-gated: the second materials sink. */
    private const string BLIGHT_HOLLOW = 'dungeon.stretch1.blight_hollow';

    /** One-time, key-gated, discipline-granting. */
    private const string SEALED_VAULT = 'dungeon.stretch1.sealed_vault';

    private const string BLIGHTLING_WATCH = 'quest.stretch1.blightling_watch';
    private const string STONEBREAKER = 'discipline.stonebreaker';

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function enter(string $characterId, string $dungeonId = self::SEALED_VAULT, array $headers = []): array
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
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function pickDiscipline(string $characterId, string $runId, string $disciplineId, array $headers = []): array
    {
        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/dungeons/runs/{$runId}/discipline",
            server: ['CONTENT_TYPE' => 'application/json', ...$headers],
            content: json_encode(['discipline_id' => $disciplineId], JSON_THROW_ON_ERROR),
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

        $this->client->request(
            'POST',
            "/api/v1/characters/{$characterId}/quests/" . self::BLIGHTLING_WATCH . '/claim',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $body = $this->decode();
        self::assertSame('claimed', $body['body']['data']['quest']['status'], 'Fixture assumes the quest is won.');
    }

    private function grantMaterial(string $characterId, string $materialId, int $amount): void
    {
        /** @var MaterialStackRepository $materialStacks */
        $materialStacks = static::getContainer()->get(MaterialStackRepository::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $entityManager->wrapInTransaction(function () use ($materialStacks, $characterId, $materialId, $amount): void {
            $now = new DateTimeImmutable();
            $stack = $materialStacks->findForUpdate(Uuid::fromString($characterId), $materialId)
                ?? new MaterialStack(Uuid::v7(), Uuid::fromString($characterId), $materialId, $now);
            $stack->add($amount, $now);

            $materialStacks->save($stack);
        });

        $entityManager->clear();
    }

    /**
     * @param list<string> $abilityIds
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function saveLoadout(string $characterId, array $abilityIds): array
    {
        $this->client->request(
            'PUT',
            '/api/v1/characters/' . $characterId . '/loadout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ability_ids' => $abilityIds], JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * Levels the character up and spends every resulting point, split evenly
     * across Constitution and Strength. An elite-tier stage (both dungeons'
     * final stage reuses encounter.stretch1.stalker) is not guaranteed
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

    // -----------------------------------------------------------------
    // Entry gates
    // -----------------------------------------------------------------

    public function testEnteringSealedVaultWithoutAKeyIsRejected(): void
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

    public function testBlightHollowRequiresItsMaterialCostNotAKey(): void
    {
        $this->registerAndLogin('nomats@example.com');
        $character = $this->createCharacter('Nomats');
        $this->levelTo($character['id'], 4);

        $response = $this->enter($character['id'], self::BLIGHT_HOLLOW);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_MATERIAL', $response['body']['error']['code']);
        self::assertSame('material.emberash', $response['body']['error']['details']['material_id']);
    }

    // -----------------------------------------------------------------
    // Clearing and the discipline offer
    // -----------------------------------------------------------------

    /**
     * A design requirement, not merely a contract, mirroring
     * EncounterFlowTest::testFreshCharacterCanWinTheFirstPatrol(): a
     * character at the dungeon's own required level must be able to clear
     * it, or the content is mistuned.
     */
    public function testEnteringSealedVaultConsumesTheKeyClearsAndOffersADiscipline(): void
    {
        $this->registerAndLogin('clear@example.com');
        $character = $this->createCharacter('Clearer');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $response = $this->enter($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        $run = $response['body']['data']['run'];

        self::assertTrue($run['cleared']);
        self::assertCount(2, $run['stages'], 'Both stages must be fought on a full clear.');

        foreach ($run['stages'] as $stage) {
            self::assertSame('victory', $stage['outcome']);
        }

        self::assertGreaterThan(0, $run['rewards']['experience']);
        self::assertGreaterThan(0, $run['rewards']['gold']);

        // Only one dungeon-tier discipline exists in content today, so the
        // offer is exactly it — min(3, remaining) with remaining = 1.
        self::assertSame([['id' => self::STONEBREAKER, 'ability_id' => 'ability.stonebreaker']], $run['offered_disciplines']);
        self::assertNull($run['picked_discipline_id']);

        // This specific dungeon is one-time: entering again is refused by
        // the "already cleared" gate, checked before the (now depleted) cost.
        $second = $this->enter($character['id']);
        self::assertSame('CONFLICT', $second['body']['error']['code']);
    }

    public function testSealedVaultRefusesReentryAfterAClearEvenWithAnotherKey(): void
    {
        $this->registerAndLogin('onetime@example.com');
        $character = $this->createCharacter('Onetime');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        self::assertSame(200, $this->enter($character['id'])['status']);

        // Simulates a second key however it might be earned later (a second
        // quest, a vendor, anything) — the point of the explicit `repeatable`
        // flag is that this must still be refused.
        $this->grantMaterial($character['id'], 'material.dungeon_key', 1);

        $second = $this->enter($character['id']);

        self::assertSame(409, $second['status']);
        self::assertSame('CONFLICT', $second['body']['error']['code']);
    }

    /**
     * A failed attempt must not lock the player out — only a clear does.
     * Simulated directly (a stored, un-cleared prior run) rather than by
     * forcing a real combat loss: this project's convention, per
     * EncounterFlowTest, is to prove win/loss behaviour through guaranteed
     * content match-ups, and no losing match-up is guaranteed here.
     */
    public function testAFailedSealedVaultAttemptDoesNotLockThePlayerOut(): void
    {
        $this->registerAndLogin('retry@example.com');
        $character = $this->createCharacter('Retry');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new DungeonRun(
            Uuid::v7(),
            Uuid::fromString($character['id']),
            self::SEALED_VAULT,
            [],
            [],
            false,
            ['experience' => 0, 'gold' => 0, 'items' => 0, 'materials' => []],
            new DateTimeImmutable(),
        ));
        $entityManager->flush();
        $entityManager->clear();

        $response = $this->enter($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
    }

    public function testBlightHollowCanBeEnteredMultipleTimesGivenRepeatedCost(): void
    {
        $this->registerAndLogin('grind@example.com');
        $character = $this->createCharacter('Grinder');
        $this->levelAndBuild($character['id'], 10);
        $this->grantMaterial($character['id'], 'material.emberash', 16);
        $this->grantMaterial($character['id'], 'material.slagiron', 4);

        $first = $this->enter($character['id'], self::BLIGHT_HOLLOW);
        self::assertSame(200, $first['status'], self::describe($first['body']));

        $second = $this->enter($character['id'], self::BLIGHT_HOLLOW);
        self::assertSame(200, $second['status'], self::describe($second['body']));
        self::assertNotSame(
            $first['body']['data']['run']['id'],
            $second['body']['data']['run']['id'],
            'A repeatable dungeon must record a fresh run each time.',
        );
    }

    // -----------------------------------------------------------------
    // Discipline pick
    // -----------------------------------------------------------------

    public function testPickingTheOfferedDisciplineGrantsAndSlotsIt(): void
    {
        $this->registerAndLogin('pick@example.com');
        $character = $this->createCharacter('Picker');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $run = $this->enter($character['id'])['body']['data']['run'];

        $picked = $this->pickDiscipline($character['id'], $run['id'], self::STONEBREAKER);

        self::assertSame(200, $picked['status'], self::describe($picked['body']));
        self::assertSame(self::STONEBREAKER, $picked['body']['data']['run']['picked_discipline_id']);

        $sheet = $this->getJson('/api/v1/characters/' . $character['id']);
        $discipline = array_values(array_filter(
            $sheet['body']['data']['character']['disciplines'],
            static fn (array $d): bool => $d['id'] === self::STONEBREAKER,
        ))[0];

        self::assertTrue($discipline['unlocked'], 'A granted dungeon discipline must show as unlocked.');

        // And it must actually be slottable — the whole point of the grant.
        // ability.rupture is the starter battle plan's ability and must stay
        // slotted alongside it; level 10 grants the three slots this needs.
        $loadout = $this->saveLoadout($character['id'], ['ability.stonebreaker', 'ability.rupture', 'ability.measured_strike']);

        self::assertSame(200, $loadout['status'], self::describe($loadout['body']));
    }

    public function testPickingANonOfferedDisciplineIsRejected(): void
    {
        $this->registerAndLogin('badpick@example.com');
        $character = $this->createCharacter('Badpick');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $run = $this->enter($character['id'])['body']['data']['run'];

        $response = $this->pickDiscipline($character['id'], $run['id'], 'discipline.measured_strike');

        self::assertSame(409, $response['status']);
        self::assertSame('CONFLICT', $response['body']['error']['code']);
    }

    public function testPickingTwiceIsRejected(): void
    {
        $this->registerAndLogin('doublepick@example.com');
        $character = $this->createCharacter('Doublepick');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $run = $this->enter($character['id'])['body']['data']['run'];
        $this->pickDiscipline($character['id'], $run['id'], self::STONEBREAKER);

        $second = $this->pickDiscipline($character['id'], $run['id'], self::STONEBREAKER);

        self::assertSame(409, $second['status']);
        self::assertSame('CONFLICT', $second['body']['error']['code']);
    }

    public function testPickingAgainstAnotherAccountsRunIsNotFound(): void
    {
        $this->registerAndLogin('runvictim@example.com');
        $victim = $this->createCharacter('Runvictim');
        $this->earnAKey($victim['id']);
        $this->levelAndBuild($victim['id'], 10);
        $run = $this->enter($victim['id'])['body']['data']['run'];

        $this->registerAndLogin('runthief@example.com');
        $thief = $this->createCharacter('Runthief');

        $response = $this->pickDiscipline($thief['id'], $run['id'], self::STONEBREAKER);

        self::assertSame(404, $response['status']);
    }

    // -----------------------------------------------------------------
    // Ownership, discovery, idempotency
    // -----------------------------------------------------------------

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

    public function testReplayingAnIdempotencyKeyEntersOnlyOnce(): void
    {
        $this->registerAndLogin('idem@example.com');
        $character = $this->createCharacter('Idempotent');
        $this->earnAKey($character['id']);
        $this->levelAndBuild($character['id'], 10);

        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'enter-once'];

        $first = $this->enter($character['id'], self::SEALED_VAULT, $headers);
        $second = $this->enter($character['id'], self::SEALED_VAULT, $headers);

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

        $rows = $connection->fetchAllAssociative(
            "SELECT event_type, payload FROM outbox WHERE event_type = 'dungeon.finished'",
        );

        self::assertCount(1, $rows);

        $payload = json_decode((string) $rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame($character['id'], $payload['characterId']);
        self::assertSame(self::SEALED_VAULT, $payload['dungeonId']);
        self::assertTrue($payload['cleared']);
        self::assertSame(2, $payload['stagesCleared']);
    }

    public function testDungeonListReportsUnlockCostAndAffordability(): void
    {
        $this->registerAndLogin('list@example.com');
        $character = $this->createCharacter('Lister');

        $before = $this->getJson('/api/v1/characters/' . $character['id'] . '/dungeons');
        self::assertSame(200, $before['status']);

        $byId = [];
        foreach ($before['body']['data']['dungeons'] as $entry) {
            $byId[$entry['id']] = $entry;
        }

        self::assertFalse($byId[self::SEALED_VAULT]['unlocked'], 'A level 1 character has not unlocked the level 4 dungeon.');
        self::assertFalse($byId[self::SEALED_VAULT]['affordable']);
        self::assertFalse($byId[self::SEALED_VAULT]['repeatable']);
        self::assertFalse($byId[self::SEALED_VAULT]['cleared']);
        self::assertTrue($byId[self::BLIGHT_HOLLOW]['repeatable']);

        $this->earnAKey($character['id']);

        $afterKey = $this->getJson('/api/v1/characters/' . $character['id'] . '/dungeons');
        $vaultAfter = array_values(array_filter(
            $afterKey['body']['data']['dungeons'],
            static fn (array $d): bool => $d['id'] === self::SEALED_VAULT,
        ))[0];

        self::assertTrue($vaultAfter['affordable']);
    }
}
