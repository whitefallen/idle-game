<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\AffixDefinition;
use App\Feature\Inventory\Domain\Model\AffixKind;
use App\Feature\Inventory\Domain\Model\AffixTier;
use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Model\ModifierMode;
use App\Feature\Inventory\Domain\Model\ModifierStat;
use App\Feature\Inventory\Domain\Service\ItemGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemGenerator::class)]
final class ItemGeneratorTest extends TestCase
{
    private static function definition(): ItemDefinition
    {
        return new ItemDefinition(
            id: 'item.test_chest',
            localisationKey: 'item.test_chest',
            slot: EquipmentSlot::Chest,
            itemLevel: 10,
            icon: 'items/test',
            vendorValue: 100,
            requiredLevel: 1,
            attributeRequirements: [],
            allowedAffixPools: ['armour_prefix', 'armour_suffix'],
            tags: ['pool.test'],
        );
    }

    /**
     * @return array<string, AffixDefinition>
     */
    private static function affixes(): array
    {
        $make = static fn (string $id, AffixKind $kind, ModifierStat $stat): AffixDefinition => new AffixDefinition(
            id: $id,
            localisationKey: $id,
            pool: $kind === AffixKind::Prefix ? 'armour_prefix' : 'armour_suffix',
            kind: $kind,
            stat: $stat,
            mode: ModifierMode::Flat,
            tiers: [
                new AffixTier(1, 1, 2, 5),
                new AffixTier(2, 15, 6, 14),
            ],
        );

        $affixes = [
            'prefix.alpha' => $make('prefix.alpha', AffixKind::Prefix, ModifierStat::ArmourValue),
            'prefix.beta' => $make('prefix.beta', AffixKind::Prefix, ModifierStat::CritChanceBp),
            'suffix.gamma' => $make('suffix.gamma', AffixKind::Suffix, ModifierStat::Constitution),
            'suffix.delta' => $make('suffix.delta', AffixKind::Suffix, ModifierStat::Luck),
        ];

        return $affixes;
    }

    /**
     * A drop must be reproducible from the encounter seed that granted it, so
     * an investigation can answer "where did this item come from" from stored
     * data rather than from trust.
     */
    public function testGenerationIsDeterministic(): void
    {
        for ($seed = 1; $seed <= 50; ++$seed) {
            $first = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);
            $second = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);

            self::assertSame($first['rarity'], $second['rarity']);
            self::assertEquals($first['affixes'], $second['affixes']);
        }
    }

    public function testDifferentSeedsProduceDifferentItems(): void
    {
        $signatures = [];

        for ($seed = 1; $seed <= 200; ++$seed) {
            $rolled = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);

            $signatures[] = $rolled['rarity']->value . ':' . json_encode(
                array_map(static fn ($a): array => $a->toArray(), $rolled['affixes']),
            );
        }

        self::assertGreaterThan(20, count(array_unique($signatures)));
    }

    public function testAffixCountMatchesTheRarityBand(): void
    {
        for ($seed = 1; $seed <= 300; ++$seed) {
            $rolled = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);
            $rarity = $rolled['rarity'];
            $count = count($rolled['affixes']);

            self::assertGreaterThanOrEqual($rarity->minimumAffixes(), $count, $rarity->value);
            self::assertLessThanOrEqual($rarity->maximumAffixes(), $count, $rarity->value);
        }
    }

    public function testAnAffixNeverAppearsTwiceOnOneItem(): void
    {
        for ($seed = 1; $seed <= 300; ++$seed) {
            $rolled = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);
            $ids = array_map(static fn ($a): string => $a->affixId, $rolled['affixes']);

            self::assertSame(count($ids), count(array_unique($ids)));
        }
    }

    public function testRolledValuesStayInsideTheTierRange(): void
    {
        for ($seed = 1; $seed <= 200; ++$seed) {
            foreach (ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0)['affixes'] as $affix) {
                // Item level 10 only unlocks tier 1, whose range is 2 to 5.
                self::assertSame(1, $affix->tier);
                self::assertGreaterThanOrEqual(2, $affix->value);
                self::assertLessThanOrEqual(5, $affix->value);
            }
        }
    }

    public function testHigherItemLevelUnlocksHigherTiers(): void
    {
        $tiers = [];

        for ($seed = 1; $seed <= 100; ++$seed) {
            foreach (ItemGenerator::generate(self::definition(), 20, 0, self::affixes(), $seed, 0)['affixes'] as $affix) {
                $tiers[$affix->tier] = true;
            }
        }

        self::assertArrayHasKey(2, $tiers, 'Item level 20 should reach tier 2.');
        self::assertArrayNotHasKey(1, $tiers, 'The best available tier is used, not a random one.');
    }

    /**
     * Luck raises the guaranteed floor rather than the ceiling, so it is a
     * plannable investment instead of a lottery ticket. See docs/items.md §8.
     */
    public function testLuckRaisesTheRarityFloorButNotTheCeiling(): void
    {
        $withoutLuck = [];
        $withLuck = [];

        for ($seed = 1; $seed <= 400; ++$seed) {
            $withoutLuck[] = ItemGenerator::rollRarity(0, $seed, 0);
            $withLuck[] = ItemGenerator::rollRarity(100, $seed, 0);
        }

        // A high-Luck character never sees the lowest bands.
        self::assertContains(ItemRarity::Common, $withoutLuck);
        self::assertNotContains(ItemRarity::Common, $withLuck);
        self::assertNotContains(ItemRarity::Uncommon, $withLuck);

        // But Legendary is no more likely than it was.
        $legendaryWithout = count(array_filter($withoutLuck, static fn ($r): bool => $r === ItemRarity::Legendary));
        $legendaryWith = count(array_filter($withLuck, static fn ($r): bool => $r === ItemRarity::Legendary));

        self::assertSame($legendaryWithout, $legendaryWith, 'Luck must not improve the ceiling.');
    }

    public function testCommonItemsCarryNoAffixes(): void
    {
        for ($seed = 1; $seed <= 500; ++$seed) {
            $rolled = ItemGenerator::generate(self::definition(), 10, 0, self::affixes(), $seed, 0);

            if ($rolled['rarity'] === ItemRarity::Common) {
                self::assertSame([], $rolled['affixes']);
            }
        }
    }

    /**
     * Stored order must not depend on roll order, or the same item could apply
     * its modifiers differently on two machines.
     */
    public function testAffixesAreStoredInAStableOrder(): void
    {
        for ($seed = 1; $seed <= 100; ++$seed) {
            $ids = array_map(
                static fn ($a): string => $a->affixId,
                ItemGenerator::generate(self::definition(), 20, 200, self::affixes(), $seed, 0)['affixes'],
            );

            $sorted = $ids;
            sort($sorted, SORT_STRING);

            self::assertSame($sorted, $ids);
        }
    }
}
