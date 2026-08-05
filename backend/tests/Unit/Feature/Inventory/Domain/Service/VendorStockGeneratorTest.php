<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Service\VendorRules;
use App\Feature\Inventory\Domain\Service\VendorStockGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(VendorStockGenerator::class)]
final class VendorStockGeneratorTest extends TestCase
{
    /**
     * @return array<string, ItemDefinition>
     */
    private static function definitionsSpanning(int $from, int $to, int $step = 5): array
    {
        $definitions = [];

        for ($ilvl = $from; $ilvl <= $to; $ilvl += $step) {
            $id = sprintf('item.test_ilvl_%d', $ilvl);
            $definitions[$id] = new ItemDefinition(
                id: $id,
                localisationKey: $id,
                slot: EquipmentSlot::Chest,
                itemLevel: $ilvl,
                icon: 'items/test',
                vendorValue: $ilvl * 10,
                requiredLevel: 1,
                attributeRequirements: [],
                allowedAffixPools: [],
                tags: [],
            );
        }

        return $definitions;
    }

    public function testGenerationIsDeterministicForTheSameCharacterAndDay(): void
    {
        $characterId = Uuid::v7();
        $definitions = self::definitionsSpanning(1, 60);

        $first = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);
        $second = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);

        self::assertCount(VendorRules::STOCK_SIZE, $first);
        self::assertEquals($first, $second);
    }

    public function testStockDiffersBetweenCharacters(): void
    {
        $definitions = self::definitionsSpanning(1, 60);

        $a = VendorStockGenerator::stockFor(Uuid::v7(), '2026-08-05', 20, 0, $definitions, []);
        $b = VendorStockGenerator::stockFor(Uuid::v7(), '2026-08-05', 20, 0, $definitions, []);

        self::assertNotEquals($a, $b);
    }

    public function testStockDiffersBetweenDays(): void
    {
        $characterId = Uuid::v7();
        $definitions = self::definitionsSpanning(1, 60);

        $today = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);
        $tomorrow = VendorStockGenerator::stockFor($characterId, '2026-08-06', 20, 0, $definitions, []);

        self::assertNotEquals($today, $tomorrow);
    }

    /**
     * The reason the reference item level has to be frozen for the day
     * (VendorStock): it is a real input to the roll, so reading it live off the
     * character would turn unequipping a weapon into a free reroll.
     */
    public function testStockChangesWithTheReferenceItemLevel(): void
    {
        $characterId = Uuid::v7();
        $definitions = self::definitionsSpanning(1, 60);

        $geared = VendorStockGenerator::stockFor($characterId, '2026-08-05', 40, 0, $definitions, []);
        $stripped = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);

        self::assertNotEquals($geared, $stripped);
    }

    public function testEveryOfferSitsInsideTheStockBand(): void
    {
        $characterId = Uuid::v7();
        $definitions = self::definitionsSpanning(1, 60);
        [$min, $max] = VendorRules::stockItemLevelBand(VendorRules::referenceItemLevel(20, 20));

        $offers = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);

        foreach ($offers as $offer) {
            self::assertGreaterThanOrEqual($min, $offer->itemLevel);
            self::assertLessThanOrEqual($max, $offer->itemLevel);
        }
    }

    public function testRarityNeverExceedsTheVendorCeiling(): void
    {
        $definitions = self::definitionsSpanning(1, 60);

        for ($seed = 0; $seed < 50; ++$seed) {
            $offers = VendorStockGenerator::stockFor(Uuid::v7(), '2026-08-05', 20, 100, $definitions, []);

            foreach ($offers as $offer) {
                self::assertNotSame(ItemRarity::Epic, $offer->rarity);
                self::assertNotSame(ItemRarity::Legendary, $offer->rarity);
            }
        }
    }

    public function testEveryOfferCarriesAPositivePrice(): void
    {
        $characterId = Uuid::v7();
        $definitions = self::definitionsSpanning(1, 60);

        $offers = VendorStockGenerator::stockFor($characterId, '2026-08-05', 20, 0, $definitions, []);

        foreach ($offers as $offer) {
            self::assertGreaterThan(0, $offer->price);
        }
    }

    /**
     * The content corpus is thin (docs/content.md section 5): a band can be
     * empty of exact matches. Stock must still fill from the closest
     * definitions rather than come back empty.
     */
    public function testFallsBackToTheNearestDefinitionsWhenTheBandIsEmpty(): void
    {
        // A single very low-level definition, nowhere near a high-level band.
        $definitions = self::definitionsSpanning(1, 1, 1);

        $offers = VendorStockGenerator::stockFor(Uuid::v7(), '2026-08-05', 55, 0, $definitions, []);

        self::assertCount(VendorRules::STOCK_SIZE, $offers);
    }

    public function testEmptyDefinitionsProduceNoStock(): void
    {
        $offers = VendorStockGenerator::stockFor(Uuid::v7(), '2026-08-05', 20, 0, [], []);

        self::assertSame([], $offers);
    }
}
