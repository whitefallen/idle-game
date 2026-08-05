<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

/**
 * Refinement: the gold-and-material sink that permanently strengthens one
 * item instance. See docs/items.md section 5.
 *
 * Refinement always succeeds — there is no failure chance, no destruction and
 * no protection item. That is a deliberate rejection of the genre norm: random
 * enhancement failure is a gambling mechanic, and escalating deterministic
 * cost buys the same pacing without turning accumulated effort into a coin
 * flip.
 */
final class RefinementRules
{
    /** A +0 item can be refined ten times, to +10. */
    public const int MAX_LEVEL = 10;

    /**
     * Each level adds 4% of the item's base stats, expressed as basis points
     * on the same percent accumulators EquipmentCalculator already applies to
     * affixes — refinement is just another percent modifier on the item's own
     * base, not a second code path.
     */
    public const int PERCENT_BP_PER_LEVEL = 400;

    public const int GOLD_COST_BASE = 40;

    public const int MATERIAL_ILVL_DIVISOR = 5;

    /**
     * The item-level band that decides which material tier refines an item,
     * highest tier first. These thresholds are not arbitrary: they are the
     * same 1/15/30/45 minIlvl bands the affix tiers already use
     * (docs/items.md section 4.1), which are themselves the levels the four
     * tiered materials unlock production at (content/materials/core.yaml).
     * Reusing them means an item's material tier is one mapping instead of a
     * second one invented for refinement alone.
     *
     * @var array<int, int>
     */
    private const array TIER_MIN_ILVL = [4 => 45, 3 => 30, 2 => 15, 1 => 1];

    private function __construct()
    {
    }

    /** `40 * ilvl * (refineLevel + 1)^2` — the cost of the *next* level. */
    public static function goldCost(int $itemLevel, int $refineLevel): int
    {
        return self::GOLD_COST_BASE * $itemLevel * ($refineLevel + 1) ** 2;
    }

    /** `ceil(ilvl / 5) * (refineLevel + 1)` units of tier-matched material. */
    public static function materialCost(int $itemLevel, int $refineLevel): int
    {
        return (int) ceil($itemLevel / self::MATERIAL_ILVL_DIVISOR) * ($refineLevel + 1);
    }

    /** The material tier that may be spent refining an item of this level. */
    public static function materialTierForItemLevel(int $itemLevel): int
    {
        foreach (self::TIER_MIN_ILVL as $tier => $minIlvl) {
            if ($itemLevel >= $minIlvl) {
                return $tier;
            }
        }

        return 1;
    }
}
