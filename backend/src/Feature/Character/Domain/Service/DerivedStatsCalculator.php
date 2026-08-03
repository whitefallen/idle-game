<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Service;

use App\Feature\Character\Domain\Model\Attribute;
use App\Feature\Character\Domain\Model\Attributes;
use App\Feature\Character\Domain\Model\DerivedStats;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;

/**
 * Turns level, attributes and equipment into the values combat consumes.
 *
 * Integer arithmetic with truncating division throughout, matching the combat
 * engine exactly. The formulas are the ones in docs/progression.md section 3;
 * that document is the specification and this class is its implementation, so
 * the two must be changed together.
 *
 * Allocated attributes and equipment bonuses are summed only here. Keeping them
 * apart in storage is what makes unequipping safe: an item can always be
 * removed, because nothing about the character's own allocation depended on it.
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

    public static function calculate(
        int $level,
        Attributes $allocated,
        ?EquipmentBonuses $equipment = null,
    ): DerivedStats {
        $equipment ??= EquipmentBonuses::none();

        $strength = $allocated->strength + $equipment->attribute(Attribute::Strength);
        $dexterity = $allocated->dexterity + $equipment->attribute(Attribute::Dexterity);
        $intelligence = $allocated->intelligence + $equipment->attribute(Attribute::Intelligence);
        $constitution = $allocated->constitution + $equipment->attribute(Attribute::Constitution);
        $luck = $allocated->luck + $equipment->attribute(Attribute::Luck);

        // The equipped main hand decides which attribute scales damage, which
        // is what makes weapon choice a build decision rather than a number
        // comparison. Unarmed falls back to Strength.
        $scalingValue = match ($equipment->scalingAttribute) {
            Attribute::Dexterity => $dexterity,
            Attribute::Intelligence => $intelligence,
            Attribute::Constitution => $constitution,
            Attribute::Luck => $luck,
            default => $strength,
        };

        $resistances = [];

        foreach (DamageSchool::cases() as $school) {
            $rating = $equipment->resistance($school);

            if ($rating > 0) {
                $resistances[$school->value] = $rating;
            }
        }

        return new DerivedStats(
            maxHealth: 50 + 12 * $constitution + 8 * $level,
            initiative: 10 * $dexterity + 5 * $level,
            maxFocus: 30 + 2 * $intelligence,
            focusPerTurn: 5 + intdiv($intelligence, 10),
            weaponBaseDamage: $equipment->hasWeapon()
                ? $equipment->weaponBaseDamage
                : self::UNARMED_BASE + self::UNARMED_PER_LEVEL * $level,
            flatDamageBonus: $equipment->flatDamage,
            scalingBp: 10000 + 70 * $scalingValue,
            critChanceBp: min(self::MAX_CRIT_CHANCE_BP, 500 + 25 * $luck + $equipment->critChanceBp),
            critPowerBp: min(self::MAX_CRIT_POWER_BP, 15000 + 20 * $luck + $equipment->critPowerBp),
            dodgeChanceBp: min(self::MAX_DODGE_BP, 200 + 12 * $dexterity + $equipment->dodgeChanceBp),
            accuracyBp: $equipment->accuracyBp,
            armourRating: $equipment->armourValue,
            resistanceRatings: $resistances,
        );
    }

    /**
     * The advisory ranking value described in ADR-0006.
     *
     * Deliberately lossy and never an input to combat, rewards, or any
     * gameplay calculation — it exists solely so leaderboards and matchmaking
     * can be a single indexed query.
     */
    public static function powerScore(
        int $level,
        Attributes $attributes,
        ?EquipmentBonuses $equipment = null,
    ): int {
        $stats = self::calculate($level, $attributes, $equipment);

        return $level * 100
            + intdiv($stats->maxHealth, 4)
            + intdiv($stats->weaponBaseDamage * $stats->scalingBp, 10000) * 3
            + intdiv($stats->critChanceBp, 100)
            + intdiv($stats->armourRating, 2);
    }
}
