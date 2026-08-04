<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Feature\Character\Domain\Model\Attribute;
use App\Feature\Character\Domain\Model\Attributes;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Character\Domain\Service\StartingLoadout;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\ComparisonOperator;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\ConditionSubject;
use App\Feature\Combat\Domain\Model\ConditionTerm;
use App\Feature\Combat\Domain\Model\PlanRule;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;
use InvalidArgumentException;

/**
 * The reference player a balance target is declared against.
 *
 * Every tolerance in {@see EncounterBalanceTest} is a statement about *this*
 * character, so what it is has to be written down rather than assumed. It is a
 * competent but unlucky player: the full attribute budget spent, a complete set
 * of unremarkable gear at the content's own item level, and the abilities the
 * loadout actually has room for.
 *
 * Three modelling choices, each load-bearing:
 *
 *  - **No affixes on the gear.** Affixes are the variance in a player's power,
 *    and a balance floor should describe the player who got none of it. A
 *    tolerance tuned against best-in-slot rolls would declare content "fair"
 *    that most players cannot clear.
 *  - **Loadout slots are respected.** A character may only slot
 *    {@see ProgressionRules::loadoutSlotsAt()} abilities, so the canonical plan
 *    grows as the character levels rather than assuming the whole library. A
 *    balance target the real game forbids is not a balance target.
 *  - **Levels 1 to 6 are hand-authored and unequipped**, matching the original
 *    stretch 1 fixture exactly. Those tolerances were tuned against that
 *    character, and re-tuning stretch 1 is not something a stretch 2 content
 *    release should quietly do.
 */
final class CanonicalBuild
{
    /**
     * Ability priority: what a player slots first as slots open up. Ordered by
     * how much a plan loses without it — the fallback and the opener first,
     * survival next, situational answers last.
     *
     * @var list<string>
     */
    private const array PRIORITY = [
        StartingLoadout::FALLBACK_ABILITY,
        'ability.rupture',
        'ability.emberdraught',
        'ability.sweeping_arc',
        'ability.reaping_blow',
        'ability.mending_tide',
        'ability.shattering_arc',
    ];

    /**
     * Attribute split, in percent of the point budget. A heavy-weapon warden:
     * Strength for damage, Constitution for the health to survive a long fight,
     * enough Intelligence to actually afford the plan's abilities, and a little
     * Dexterity and Luck. Constitution takes the rounding remainder.
     */
    private const int STRENGTH_PERCENT = 38;

    private const int DEXTERITY_PERCENT = 7;

    private const int INTELLIGENCE_PERCENT = 10;

    private const int LUCK_PERCENT = 5;

    /** docs/items.md section 3: a heavy weapon's class multiplier. */
    private const int HEAVY_WEAPON_BP = 13000;

    /**
     * docs/items.md section 3: the five armour slot weights summed
     * (Chest 13000 + Legs 11000 + Head 9000 + Hands 7000 + Feet 7000). Amulets
     * and rings carry no base armour, so they contribute nothing here.
     */
    private const int ARMOUR_SLOTS_BP = 47000;

    private function __construct()
    {
    }

    public static function attributesAt(int $level): Attributes
    {
        if ($level < 1 || $level > ProgressionRules::MAX_LEVEL) {
            throw new InvalidArgumentException(
                sprintf('No canonical build is defined for level %d.', $level),
            );
        }

        // The three levels the stretch 1 tolerances were tuned against, kept
        // verbatim. They deliberately leave points unspent, which is what a
        // player who has not yet thought about allocation actually looks like.
        $authored = [
            1 => Attributes::starting(),
            4 => Attributes::of(9, 5, 5, 11, 5),
            6 => Attributes::of(12, 6, 5, 15, 5),
        ];

        if (isset($authored[$level])) {
            return $authored[$level];
        }

        $budget = ProgressionRules::totalAttributePointsAt($level);

        $strength = intdiv($budget * self::STRENGTH_PERCENT, 100);
        $dexterity = intdiv($budget * self::DEXTERITY_PERCENT, 100);
        $intelligence = intdiv($budget * self::INTELLIGENCE_PERCENT, 100);
        $luck = intdiv($budget * self::LUCK_PERCENT, 100);
        $constitution = $budget - $strength - $dexterity - $intelligence - $luck;

        $base = Attributes::BASE_VALUE;

        return Attributes::of(
            $base + $strength,
            $base + $dexterity,
            $base + $intelligence,
            $base + $constitution,
            $base + $luck,
        );
    }

    /**
     * The gear a player of this level is expected to be wearing: a full set at
     * item level equal to character level, no affixes, no refinement.
     *
     * Levels 1 to 6 are unequipped, which is what the stretch 1 tolerances were
     * tuned against.
     */
    public static function equipmentAt(int $level): ?EquipmentBonuses
    {
        if ($level <= 6) {
            return null;
        }

        return new EquipmentBonuses(
            armourValue: intdiv((5 + 3 * $level) * self::ARMOUR_SLOTS_BP, 10000),
            weaponBaseDamage: intdiv((8 + 4 * $level) * self::HEAVY_WEAPON_BP, 10000),
            scalingAttribute: Attribute::Strength,
        );
    }

    /**
     * @return list<string>
     */
    public static function abilitiesAt(int $level): array
    {
        if ($level <= 6) {
            return StartingLoadout::abilityIds();
        }

        return array_slice(self::PRIORITY, 0, ProgressionRules::loadoutSlotsAt($level));
    }

    /**
     * The plan the canonical player fights by.
     *
     * One rule per slotted ability, in a fixed priority order: survive, then
     * answer the situation, then hit things. Rules whose ability is not slotted
     * at this level are omitted rather than left dangling, because a plan naming
     * an unlearned ability is exactly what the server rejects on save.
     */
    public static function planAt(int $level): BattlePlan
    {
        if ($level <= 6) {
            return StartingLoadout::battlePlan();
        }

        $slotted = self::abilitiesAt($level);

        /** @var list<array{string, ConditionSubject, ComparisonOperator, int}> $conditional */
        $conditional = [
            ['ability.emberdraught', ConditionSubject::SelfHealthPercent, ComparisonOperator::LessThan, 35],
            ['ability.mending_tide', ConditionSubject::SelfHealthPercent, ComparisonOperator::LessThan, 60],
            ['ability.shattering_arc', ConditionSubject::EnemyCount, ComparisonOperator::GreaterOrEqual, 3],
            ['ability.sweeping_arc', ConditionSubject::EnemyCount, ComparisonOperator::GreaterOrEqual, 2],
            ['ability.reaping_blow', ConditionSubject::TargetHealthPercent, ComparisonOperator::LessThan, 40],
        ];

        $rules = [];

        foreach ($conditional as [$abilityId, $subject, $operator, $value]) {
            if (in_array($abilityId, $slotted, true)) {
                $rules[] = new PlanRule(
                    new Condition([ConditionTerm::numeric($subject, $operator, $value)]),
                    $abilityId,
                );
            }
        }

        if (in_array('ability.rupture', $slotted, true)) {
            $rules[] = new PlanRule(Condition::always(), 'ability.rupture');
        }

        $rules[] = new PlanRule(Condition::always(), StartingLoadout::FALLBACK_ABILITY);

        return new BattlePlan($rules);
    }
}
