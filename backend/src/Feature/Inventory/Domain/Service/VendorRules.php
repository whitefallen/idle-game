<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\ItemRarity;

/**
 * Pure formulas for the Vendor's stock band and pricing.
 *
 * The Vendor is the gold sink that replaced durability/repair (see
 * docs/items.md section 6): instead of draining a little gold on every
 * encounter, it prices its best offers against how far ahead of a character's
 * current gear they sit, so spend still tracks engagement without punishing a
 * player who stops playing. See docs/items.md section 5 for the design.
 */
final class VendorRules
{
    /** Offers rolled per character per day. */
    public const int STOCK_SIZE = 8;

    /** How far below the reference item level stock can roll. */
    public const int BAND_BELOW_ILVL = 5;

    /**
     * How far above the reference item level stock can roll.
     *
     * Wider than the floor side on purpose: the Vendor is meant to be a
     * source of real upgrades, not just a lateral option, so most of the band
     * sits above a character's current gear.
     */
    public const int BAND_ABOVE_ILVL = 15;

    /** Vendor markup at the reference item level, in basis points (1.2x). */
    public const int BASE_MARKUP_BP = 12000;

    /** Additional markup per item level above the reference, in basis points. */
    public const int MARKUP_PER_ILVL_ABOVE_BP = 600;

    /**
     * Legendary and Epic never appear in vendor stock — the loot chase stays
     * the only way to reach them. Rare is still a real, valuable upgrade, so
     * gold buys meaningfully into progression without buying the ceiling.
     */
    public const ItemRarity RARITY_CEILING = ItemRarity::Rare;

    private function __construct()
    {
    }

    /**
     * The item level stock is priced and rolled against: a character's
     * average equipped item level, floored by their own level so a fresh or
     * unequipped character still sees sensible stock.
     */
    public static function referenceItemLevel(int $characterLevel, int $averageEquippedItemLevel): int
    {
        return max($characterLevel, $averageEquippedItemLevel);
    }

    /**
     * @return array{0: int, 1: int} Inclusive [min, max] item level band.
     */
    public static function stockItemLevelBand(int $referenceItemLevel): array
    {
        return [
            max(1, $referenceItemLevel - self::BAND_BELOW_ILVL),
            $referenceItemLevel + self::BAND_ABOVE_ILVL,
        ];
    }

    /**
     * Caps a rolled rarity at RARITY_CEILING, the mirror image of the Luck
     * floor in ItemGenerator::rollRarity — that raises a minimum, this lowers
     * a maximum, and both express the clamp as an index into the same
     * ascending() ordering rather than a per-rarity special case.
     */
    public static function capRarity(ItemRarity $rolled): ItemRarity
    {
        $bands = ItemRarity::ascending();
        // Both are always members of ascending(), so the cast never actually
        // falls back to 0 — it only satisfies PHPStan's int|false from
        // array_search.
        $rolledIndex = (int) array_search($rolled, $bands, true);
        $ceilingIndex = (int) array_search(self::RARITY_CEILING, $bands, true);

        return $bands[min($rolledIndex, $ceilingIndex)];
    }

    /**
     * buyPrice = vendorValue x markup(how far above reference this offer
     * sits) x rarity multiplier. vendorValue stays the one source of an
     * item's base worth (items.md section 7); this layers the Vendor's own
     * pricing on top rather than inventing a second notion of value.
     */
    public static function buyPrice(
        int $vendorValue,
        int $offerItemLevel,
        int $referenceItemLevel,
        ItemRarity $rarity,
    ): int {
        $gapAboveReference = max(0, $offerItemLevel - $referenceItemLevel);
        $markupBp = self::BASE_MARKUP_BP + $gapAboveReference * self::MARKUP_PER_ILVL_ABOVE_BP;

        $afterMarkup = intdiv($vendorValue * $markupBp, 10000);

        return intdiv($afterMarkup * self::rarityMultiplierBp($rarity), 10000);
    }

    private static function rarityMultiplierBp(ItemRarity $rarity): int
    {
        return match ($rarity) {
            ItemRarity::Common => 10000,
            ItemRarity::Uncommon => 13000,
            ItemRarity::Rare, ItemRarity::Epic, ItemRarity::Legendary => 18000,
        };
    }
}
