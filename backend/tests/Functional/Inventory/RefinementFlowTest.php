<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class RefinementFlowTest extends ApiTestCase
{
    /**
     * item.tempered_hauberk at ilvl 5: goldCost(5, 0) = 200, materialCost(5, 0)
     * = 1, tier 1 (material.emberash). See RefinementRulesTest for the formula
     * this pins.
     */
    private const string ITEM_DEFINITION = 'item.tempered_hauberk';

    private const int ITEM_LEVEL = 5;

    private const string TIER1_MATERIAL = 'material.emberash';

    private const string TIER2_MATERIAL = 'material.slagiron';

    private function grantItem(string $characterId): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $item = new ItemInstance(
            Uuid::v7(),
            Uuid::fromString($characterId),
            self::ITEM_DEFINITION,
            self::ITEM_LEVEL,
            ItemRarity::Common,
            [],
            new DateTimeImmutable(),
        );

        $entityManager->persist($item);
        $entityManager->flush();

        return $item->id()->toRfc4122();
    }

    private function grantGold(string $characterId, int $amount): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var Character $character */
        $character = $entityManager->find(Character::class, Uuid::fromString($characterId));

        $character->awardGold($amount, new DateTimeImmutable());

        $entityManager->flush();
        $entityManager->clear();
    }

    private function grantMaterial(string $characterId, string $materialId, int $amount): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $now = new DateTimeImmutable();
        $stack = new MaterialStack(Uuid::v7(), Uuid::fromString($characterId), $materialId, $now);
        $stack->add($amount, $now);

        $entityManager->persist($stack);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function refine(string $itemId, string $materialId): array
    {
        return $this->postJson('/api/v1/items/' . $itemId . '/refine', ['material_id' => $materialId]);
    }

    public function testRefiningSpendsGoldAndMaterialAndAdvancesTheLevel(): void
    {
        $this->registerAndLogin('refiner@example.com');
        $character = $this->createCharacter('Refiner');
        $itemId = $this->grantItem($character['id']);

        $this->grantGold($character['id'], 200);
        $this->grantMaterial($character['id'], self::TIER1_MATERIAL, 1);

        $response = $this->refine($itemId, self::TIER1_MATERIAL);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame(1, $response['body']['data']['item']['refinement']['level']);
        self::assertSame(0, $response['body']['data']['character']['gold']);
    }

    public function testRefiningAnEquippedItemRaisesItsContributionToArmour(): void
    {
        $this->registerAndLogin('equippedrefiner@example.com');
        $character = $this->createCharacter('Equippedrefiner');

        // item.tempered_hauberk needs level 3 and 10 Constitution (EquipmentTest).
        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['CON' => 5]]);

        $itemId = $this->grantItem($character['id']);
        $equipped = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);
        self::assertSame(200, $equipped['status'], self::describe($equipped['body']));

        $before = $this->getJson('/api/v1/characters/' . $character['id'])
            ['body']['data']['character']['derived_stats']['armourRating'];

        $this->grantGold($character['id'], 200);
        $this->grantMaterial($character['id'], self::TIER1_MATERIAL, 1);

        $response = $this->refine($itemId, self::TIER1_MATERIAL);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        // ilvl 5 chest base armour is 26 (EquipmentTest); +1 refine level adds
        // 4%: intdiv(26 * 10400, 10000) = 27.
        $after = $response['body']['data']['character']['derived_stats']['armourRating'];
        self::assertGreaterThan($before, $after);
        self::assertSame($after, $response['body']['data']['character']['derived_stats']['armourRating']);
    }

    public function testRefiningWithoutEnoughGoldFails(): void
    {
        $this->registerAndLogin('poor@example.com');
        $character = $this->createCharacter('Poor');
        $itemId = $this->grantItem($character['id']);

        $this->grantMaterial($character['id'], self::TIER1_MATERIAL, 1);

        // A fresh character has no gold, so nothing further needs granting.
        $response = $this->refine($itemId, self::TIER1_MATERIAL);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_GOLD', $response['body']['error']['code']);
        self::assertSame(200, $response['body']['error']['details']['required']);
        self::assertSame(0, $response['body']['error']['details']['available']);
    }

    public function testRefiningWithoutEnoughMaterialFails(): void
    {
        $this->registerAndLogin('understocked@example.com');
        $character = $this->createCharacter('Understocked');
        $itemId = $this->grantItem($character['id']);

        $this->grantGold($character['id'], 200);

        $response = $this->refine($itemId, self::TIER1_MATERIAL);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_MATERIAL', $response['body']['error']['code']);
        self::assertSame(1, $response['body']['error']['details']['required']);
        self::assertSame(0, $response['body']['error']['details']['available']);
    }

    /**
     * The item is ilvl 5, tier 1. Slagiron is tier 2 — a real material, just
     * the wrong one for this item, which must be rejected the same as an
     * unknown id rather than silently accepted because the character happens
     * to hold enough of it.
     */
    public function testRefiningWithAMismatchedMaterialTierFails(): void
    {
        $this->registerAndLogin('wrongtier@example.com');
        $character = $this->createCharacter('Wrongtier');
        $itemId = $this->grantItem($character['id']);

        $this->grantGold($character['id'], 200);
        $this->grantMaterial($character['id'], self::TIER2_MATERIAL, 10);

        $response = $this->refine($itemId, self::TIER2_MATERIAL);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    public function testRefiningStopsAtTheCap(): void
    {
        $this->registerAndLogin('maxed@example.com');
        $character = $this->createCharacter('Maxed');
        $itemId = $this->grantItem($character['id']);

        // Comfortably more than the roughly 77,000 gold and 55 material a
        // full +0 to +10 climb costs at ilvl 5 (RefinementRulesTest pins the
        // per-level formula this sums).
        $this->grantGold($character['id'], 200_000);
        $this->grantMaterial($character['id'], self::TIER1_MATERIAL, 200);

        for ($i = 0; $i < 10; ++$i) {
            $response = $this->refine($itemId, self::TIER1_MATERIAL);
            self::assertSame(200, $response['status'], self::describe($response['body']));
        }

        self::assertSame(10, $response['body']['data']['item']['refinement']['level']);
        self::assertTrue($response['body']['data']['item']['refinement']['at_cap']);

        $overCap = $this->refine($itemId, self::TIER1_MATERIAL);

        self::assertSame(422, $overCap['status']);
        self::assertSame('VALIDATION_FAILED', $overCap['body']['error']['code']);
    }

    public function testCannotRefineAnotherAccountsItem(): void
    {
        $this->registerAndLogin('owner@example.com');
        $character = $this->createCharacter('Owner');
        $itemId = $this->grantItem($character['id']);

        $this->registerAndLogin('thief@example.com');

        self::assertSame(404, $this->refine($itemId, self::TIER1_MATERIAL)['status']);
    }

    /**
     * A retried request with the same idempotency key must replay the first
     * outcome rather than charge gold and material twice — the same guarantee
     * HoldingController::claim gives, for the same reason: this endpoint
     * spends real resources.
     */
    public function testRepeatingTheSameIdempotencyKeyDoesNotDoubleCharge(): void
    {
        $this->registerAndLogin('retrier@example.com');
        $character = $this->createCharacter('Retrier');
        $itemId = $this->grantItem($character['id']);

        $this->grantGold($character['id'], 200);
        $this->grantMaterial($character['id'], self::TIER1_MATERIAL, 1);

        $this->client->request(
            'POST',
            '/api/v1/items/' . $itemId . '/refine',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'refine-once'],
            content: json_encode(['material_id' => self::TIER1_MATERIAL], JSON_THROW_ON_ERROR),
        );
        $first = $this->decode();
        self::assertSame(200, $first['status'], self::describe($first['body']));

        $this->client->request(
            'POST',
            '/api/v1/items/' . $itemId . '/refine',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'refine-once'],
            content: json_encode(['material_id' => self::TIER1_MATERIAL], JSON_THROW_ON_ERROR),
        );
        $second = $this->decode();

        self::assertSame(200, $second['status']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame($first['body'], $second['body']);

        // Charged once, not twice.
        self::assertSame(1, $second['body']['data']['item']['refinement']['level']);
        self::assertSame(0, $second['body']['data']['character']['gold']);
    }
}
