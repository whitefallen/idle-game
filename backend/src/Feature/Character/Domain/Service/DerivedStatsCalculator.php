<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Service;

use App\Feature\Character\Domain\Model\Attributes;
use App\Feature\Character\Domain\Model\DerivedStats;

/**
 * Turns level and attributes into the values combat consumes.
 *
 * Integer arithmetic with truncating division throughout, matching the combat
 * engine exactly. The formulas are the ones in docs/progression.md section 3;
 * that document is the specification and this class is its implementation, so
 * the two must be changed together.
 */
final class DerivedStatsCalculator
{
    /**
     * A character with no weapon still fights. This is the unarmed baseline,
     * not a stand-in: it scales with Strength like a heavy weapon and stays
     * deliberately weak so that a first weapon is a felt upgrade.
     */
    private const int UNARMED_BASE = 6;

    private const int UNARMED_PER_LEVEL = 2;

    private const int MAX_CRIT_CHANCE_BP = 5000;

    private const int MAX_CRIT_POWER_BP = 25000;

    /**
     * Dodge is capped far below armour because it is all-or-nothing, and high
     * dodge produces wildly swingy fights that feel arbitrary to plan around.
     */
    private const int MAX_DODGE_BP = 2500;

    private function __construct()
    {
    }

    public static function calculate(int $level, Attributes $attributes): DerivedStats
    {
        return new DerivedStats(
            maxHealth: 50 + 12 * $attributes->constitution + 8 * $level,
            initiative: 10 * $attributes->dexterity + 5 * $level,
            maxFocus: 30 + 2 * $attributes->intelligence,
            focusPerTurn: 5 + intdiv($attributes->intelligence, 10),
            weaponBaseDamage: self::UNARMED_BASE + self::UNARMED_PER_LEVEL * $level,
            flatDamageBonus: 0,
            scalingBp: 10000 + 70 * $attributes->strength,
            critChanceBp: min(self::MAX_CRIT_CHANCE_BP, 500 + 25 * $attributes->luck),
            critPowerBp: min(self::MAX_CRIT_POWER_BP, 15000 + 20 * $attributes->luck),
            dodgeChanceBp: min(self::MAX_DODGE_BP, 200 + 12 * $attributes->dexterity),
            accuracyBp: 0,
            armourRating: 0,
            resistanceRatings: [],
        );
    }

    /**
     * The advisory ranking value described in ADR-0006.
     *
     * Deliberately lossy and never an input to combat, rewards, or any
     * gameplay calculation — it exists solely so leaderboards and matchmaking
     * can be a single indexed query.
     */
    public static function powerScore(int $level, Attributes $attributes): int
    {
        $stats = self::calculate($level, $attributes);

        return $level * 100
            + intdiv($stats->maxHealth, 4)
            + intdiv($stats->weaponBaseDamage * $stats->scalingBp, 10000) * 3
            + intdiv($stats->critChanceBp, 100)
            + intdiv($stats->armourRating, 2);
    }
}
