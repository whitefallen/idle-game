<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\Participant;

/**
 * The canonical damage calculation.
 *
 * Every step truncates immediately (determinism rule R2). Intermediate
 * precision is deliberately not carried: it makes the formula mean exactly what
 * it says, and lets a reimplementation in another language match without a
 * rounding-convention appendix.
 *
 * The order of steps is fixed and is part of the balance design, not an
 * implementation detail. See docs/combat.md section 6.
 */
final class DamagePipeline
{
    /** Mitigation is capped so even a fully defensive build still takes damage. */
    public const int MAX_MITIGATION_BP = 7500;

    /**
     * The attacker-level term in the mitigation curve. It makes armour decay in
     * relative value against higher-level opponents automatically, without any
     * explicit rescaling patch each content tier.
     */
    private const int MITIGATION_LEVEL_FACTOR = 60;

    private const int MITIGATION_CONSTANT = 300;

    private const int BP = 10000;

    private function __construct()
    {
    }

    /**
     * Steps 1 to 6: everything the attacker contributes, before the defender's
     * mitigation. Shared by damage and healing so that a heal scales with the
     * same power budget as an attack.
     */
    private static function attackerOutput(
        Participant $attacker,
        int $modifierBp,
        int $coefficientBp,
    ): int {
        // 1. Base stats — the weapon's own damage.
        $value = $attacker->weaponBaseDamage;

        // 2. Equipment — flat additions from affixes.
        $value += $attacker->flatDamageBonus;

        // 3. Attribute scaling.
        $value = intdiv($value * $attacker->scalingBp, self::BP);

        // 4. Buffs and debuffs, additive among themselves.
        //    Clamped so a stack of debuffs can reduce output to zero but never
        //    invert it into healing the target.
        $value = intdiv($value * (self::BP + max(-self::BP, $modifierBp)), self::BP);

        // 5. The ability's own coefficient.
        return intdiv($value * $coefficientBp, self::BP);
    }

    /**
     * The full pipeline, returning the health the defender actually loses.
     */
    public static function damage(
        Participant $attacker,
        int $attackerModifierBp,
        Participant $defender,
        Ability $ability,
        bool $isCritical,
    ): int {
        $value = self::attackerOutput($attacker, $attackerModifierBp, $ability->damageCoefficientBp);

        // 6. Critical hit.
        if ($isCritical) {
            $value = intdiv($value * $attacker->critPowerBp, self::BP);
        }

        // 7. Armour.
        $value = intdiv(
            $value * (self::BP - self::mitigationBp($defender->armourRating, $attacker->level)),
            self::BP,
        );

        // 8. School resistance.
        $value = intdiv(
            $value * (self::BP - self::mitigationBp($defender->resistanceRatingFor($ability->school), $attacker->level)),
            self::BP,
        );

        // 9. Floor of one. Guarantees a fight can never stall against an
        //    over-armoured target, which together with the round cap guarantees
        //    the engine terminates.
        return max(1, $value);
    }

    /**
     * Healing is not mitigated and cannot critically strike.
     *
     * Both are deliberate: mitigated healing would make defensive stats reduce
     * a character's own sustain, and critical healing adds variance to the one
     * part of a fight a player most needs to be able to plan around.
     */
    public static function healing(Participant $healer, int $modifierBp, Ability $ability): int
    {
        return max(0, self::attackerOutput($healer, $modifierBp, $ability->healCoefficientBp));
    }

    /**
     * The hyperbolic mitigation curve shared by armour and resistances.
     *
     * Hyperbolic rather than linear so that returns diminish and immunity is
     * unreachable at any rating.
     */
    public static function mitigationBp(int $rating, int $attackerLevel): int
    {
        if ($rating <= 0) {
            return 0;
        }

        $denominator = $rating + self::MITIGATION_LEVEL_FACTOR * $attackerLevel + self::MITIGATION_CONSTANT;

        return min(self::MAX_MITIGATION_BP, intdiv(self::BP * $rating, $denominator));
    }

    /**
     * Dodge, reduced by the attacker's accuracy and floored at zero.
     */
    public static function effectiveDodgeBp(Participant $attacker, Participant $defender): int
    {
        return max(0, $defender->dodgeChanceBp - $attacker->accuracyBp);
    }

    /**
     * Exposed so callers can reason about school coverage without reaching into
     * the participant's raw array.
     */
    public static function resistanceBpFor(Participant $defender, DamageSchool $school, int $attackerLevel): int
    {
        return self::mitigationBp($defender->resistanceRatingFor($school), $attackerLevel);
    }
}
