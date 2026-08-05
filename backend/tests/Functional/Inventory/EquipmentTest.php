<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Model\RolledAffix;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EquipmentTest extends ApiTestCase
{
    /**
     * Grants an item directly, so a test can exercise equipping without
     * fighting until the drop table cooperates.
     *
     * @param list<RolledAffix> $affixes
     */
    private function grant(string $characterId, string $definitionId, int $itemLevel, array $affixes = []): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $item = new ItemInstance(
            Uuid::v7(),
            Uuid::fromString($characterId),
            $definitionId,
            $itemLevel,
            $affixes === [] ? ItemRarity::Common : ItemRarity::Rare,
            $affixes,
            new \DateTimeImmutable(),
        );

        $entityManager->persist($item);
        $entityManager->flush();

        return $item->id()->toRfc4122();
    }

    /**
     * @return array<string, mixed>
     */
    private function statsOf(string $characterId): array
    {
        return $this->getJson('/api/v1/characters/' . $characterId)['body']['data']['character']['derived_stats'];
    }

    /**
     * The whole point of the feature: before equipping, armour is zero.
     */
    public function testEquippingArmourRaisesTheArmourRating(): void
    {
        $this->registerAndLogin('equipper@example.com');
        $character = $this->createCharacter('Equipper');

        // The hauberk needs level 3 and 10 Constitution.
        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['CON' => 5],
        ]);

        self::assertSame(0, $this->statsOf($character['id'])['armourRating']);

        $itemId = $this->grant($character['id'], 'item.tempered_hauberk', 5);

        $response = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame('Chest', $response['body']['data']['item']['equipped_slot']);

        // ilvl 5 chest: (5 + 3*5) * 13000 / 10000 = 26.
        self::assertSame(26, $response['body']['data']['character']['derived_stats']['armourRating']);
    }

    public function testAffixesContributeToDerivedStats(): void
    {
        $this->registerAndLogin('affixed@example.com');
        $character = $this->createCharacter('Affixed');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['CON' => 5],
        ]);

        $itemId = $this->grant($character['id'], 'item.tempered_hauberk', 5, [
            new RolledAffix('prefix.reinforced', 1, 4),   // +4 flat armour
            new RolledAffix('suffix.of_the_ox', 1, 3),    // +3 Constitution
        ]);

        $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        $stats = $this->statsOf($character['id']);

        // Base 26 plus 4 flat.
        self::assertSame(30, $stats['armourRating']);

        // Level 4, Constitution 10 allocated plus 3 from the affix:
        // 50 + 12*13 + 8*4 = 238.
        self::assertSame(238, $stats['maxHealth']);
    }

    /**
     * Percentages apply after flats, so the order is predictable and a player
     * comparing two items can work out which is better.
     */
    public function testPercentageAffixesApplyAfterFlatOnes(): void
    {
        $this->registerAndLogin('percent@example.com');
        $character = $this->createCharacter('Percentage');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['CON' => 5],
        ]);

        $itemId = $this->grant($character['id'], 'item.tempered_hauberk', 5, [
            new RolledAffix('prefix.reinforced', 1, 4),   // flat +4
            new RolledAffix('prefix.tempered', 1, 1000),  // +10%
        ]);

        $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        // (26 + 4) * 1.10 = 33. Applying the percentage first would give 32.
        self::assertSame(33, $this->statsOf($character['id'])['armourRating']);
    }

    /**
     * The weapon decides which attribute scales damage, which is what makes
     * weapon choice a build decision rather than a number comparison.
     */
    public function testAWeaponSetsBaseDamageAndItsScalingAttribute(): void
    {
        $this->registerAndLogin('armed@example.com');
        $character = $this->createCharacter('Armed');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['STR' => 7],
        ]);

        $before = $this->statsOf($character['id']);

        $itemId = $this->grant($character['id'], 'item.wardens_halberd', 6);
        $response = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $after = $response['body']['data']['character']['derived_stats'];

        // Heavy weapon at ilvl 6: (8 + 4*6) * 13000 / 10000 = 41, replacing the
        // unarmed baseline entirely.
        self::assertSame(41, $after['weaponBaseDamage']);
        self::assertGreaterThan($before['weaponBaseDamage'], $after['weaponBaseDamage']);

        // Heavy scales with Strength: 12 allocated.
        self::assertSame(10000 + 70 * 12, $after['scalingBp']);
    }

    public function testRequirementsAreEnforcedAtTheMomentOfEquipping(): void
    {
        $this->registerAndLogin('underqualified@example.com');
        $character = $this->createCharacter('Underqualified');

        // Level 1 with 5 Strength; the halberd needs level 4 and 12 Strength.
        $itemId = $this->grant($character['id'], 'item.wardens_halberd', 6);

        $response = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        self::assertSame(422, $response['status']);
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame(4, $response['body']['error']['details']['required_level']);
    }

    public function testEquippingASecondItemDisplacesTheFirst(): void
    {
        $this->registerAndLogin('swapper@example.com');
        $character = $this->createCharacter('Swapper');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['CON' => 5]]);

        $first = $this->grant($character['id'], 'item.tempered_hauberk', 5);
        $second = $this->grant($character['id'], 'item.tempered_hauberk', 5);

        $this->postJson('/api/v1/items/' . $first . '/equip', []);
        $this->postJson('/api/v1/items/' . $second . '/equip', []);

        $inventory = $this->getJson('/api/v1/characters/' . $character['id'] . '/inventory')['body']['data'];

        // A slot holds one item, so the first is back in the inventory.
        self::assertCount(1, $inventory['equipped']);
        self::assertSame($second, $inventory['equipped'][0]['id']);
        self::assertCount(1, $inventory['carried']);
        self::assertSame($first, $inventory['carried'][0]['id']);
    }

    public function testRingsMayBeWornInEitherRingSlot(): void
    {
        $this->registerAndLogin('ringed@example.com');
        $character = $this->createCharacter('Ringed');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['CON' => 5]]);

        $first = $this->grant($character['id'], 'item.ember_band', 5);
        $second = $this->grant($character['id'], 'item.ember_band', 5);

        self::assertSame(200, $this->postJson('/api/v1/items/' . $first . '/equip', ['slot' => 'Ring1'])['status']);
        self::assertSame(200, $this->postJson('/api/v1/items/' . $second . '/equip', ['slot' => 'Ring2'])['status']);

        $inventory = $this->getJson('/api/v1/characters/' . $character['id'] . '/inventory')['body']['data'];

        self::assertCount(2, $inventory['equipped'], 'Both ring slots may be filled at once.');
    }

    public function testAnItemCannotBeWornInAnUnrelatedSlot(): void
    {
        $this->registerAndLogin('wrongslot@example.com');
        $character = $this->createCharacter('Wrongslot');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['CON' => 5]]);

        $itemId = $this->grant($character['id'], 'item.tempered_hauberk', 5);

        $response = $this->postJson('/api/v1/items/' . $itemId . '/equip', ['slot' => 'Head']);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    public function testUnequippingRestoresTheUnarmedBaseline(): void
    {
        $this->registerAndLogin('disarmed@example.com');
        $character = $this->createCharacter('Disarmed');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['STR' => 7]]);

        $itemId = $this->grant($character['id'], 'item.wardens_halberd', 6);
        $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        $response = $this->postJson('/api/v1/items/' . $itemId . '/unequip', []);

        self::assertSame(200, $response['status']);
        self::assertNull($response['body']['data']['item']['equipped_slot']);

        // Back to the unarmed baseline at level 4: 6 + 2*4 = 14.
        self::assertSame(14, $response['body']['data']['character']['derived_stats']['weaponBaseDamage']);
    }

    /**
     * The advisory ranking column has to follow equipment, or leaderboards rank
     * players by gear they are not wearing. See ADR-0006.
     */
    public function testPowerScoreFollowsEquipment(): void
    {
        $this->registerAndLogin('ranked@example.com');
        $character = $this->createCharacter('Ranked');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['STR' => 7]]);

        $before = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character']['power_score'];

        $itemId = $this->grant($character['id'], 'item.wardens_halberd', 6);
        $equipped = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);

        self::assertGreaterThan($before, $equipped['body']['data']['character']['power_score']);

        $unequipped = $this->postJson('/api/v1/items/' . $itemId . '/unequip', []);

        self::assertSame($before, $unequipped['body']['data']['character']['power_score']);
    }

    public function testCannotEquipAnotherAccountsItem(): void
    {
        $this->registerAndLogin('itemowner@example.com');
        $character = $this->createCharacter('Itemowner');
        $itemId = $this->grant($character['id'], 'item.tempered_hauberk', 5);

        $this->registerAndLogin('itemthief@example.com');

        self::assertSame(404, $this->postJson('/api/v1/items/' . $itemId . '/equip', [])['status']);
    }
}
