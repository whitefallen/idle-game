<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Service\VendorRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VendorRules::class)]
final class VendorRulesTest extends TestCase
{
    public function testReferenceItemLevelIsTheHigherOfLevelAndGear(): void
    {
        self::assertSame(20, VendorRules::referenceItemLevel(20, 5), 'A fresh or unequipped character floors on level.');
        self::assertSame(30, VendorRules::referenceItemLevel(20, 30), 'A well-geared character floors on gear.');
    }

    public function testStockBandSitsMoreAboveThanBelowTheReference(): void
    {
        [$min, $max] = VendorRules::stockItemLevelBand(20);

        self::assertSame(15, $min);
        self::assertSame(35, $max);
        self::assertGreaterThan(20 - $min, $max - 20, 'The Vendor should skew toward real upgrades, not lateral offers.');
    }

    public function testStockBandNeverGoesBelowItemLevelOne(): void
    {
        [$min] = VendorRules::stockItemLevelBand(2);

        self::assertSame(1, $min);
    }

    public function testRarityIsCappedAtRare(): void
    {
        self::assertSame(ItemRarity::Common, VendorRules::capRarity(ItemRarity::Common));
        self::assertSame(ItemRarity::Rare, VendorRules::capRarity(ItemRarity::Rare));
        self::assertSame(ItemRarity::Rare, VendorRules::capRarity(ItemRarity::Epic));
        self::assertSame(ItemRarity::Rare, VendorRules::capRarity(ItemRarity::Legendary));
    }

    public function testPriceIsExactlyVendorValueAtTheReferenceLevel(): void
    {
        // 12000bp markup, 10000bp (1x) rarity multiplier for Common, no gap.
        self::assertSame(1200, VendorRules::buyPrice(1000, 20, 20, ItemRarity::Common));
    }

    public function testPriceGrowsWithTheGapAboveReference(): void
    {
        $atReference = VendorRules::buyPrice(1000, 20, 20, ItemRarity::Common);
        $fiveAbove = VendorRules::buyPrice(1000, 25, 20, ItemRarity::Common);
        $tenAbove = VendorRules::buyPrice(1000, 30, 20, ItemRarity::Common);

        self::assertGreaterThan($atReference, $fiveAbove);
        self::assertGreaterThan($fiveAbove, $tenAbove);
    }

    public function testPriceNeverDropsBelowVendorValueEvenBelowReference(): void
    {
        // Offers below the reference still carry the base markup — the Vendor
        // is never a below-value discount vendor, only a markup one.
        $price = VendorRules::buyPrice(1000, 10, 20, ItemRarity::Common);

        self::assertGreaterThanOrEqual(1000, $price);
    }

    public function testRareCostsMoreThanCommonAtTheSameItemLevel(): void
    {
        $common = VendorRules::buyPrice(1000, 20, 20, ItemRarity::Common);
        $rare = VendorRules::buyPrice(1000, 20, 20, ItemRarity::Rare);

        self::assertGreaterThan($common, $rare);
    }
}
