<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Support;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\ComparisonOperator;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\ConditionSubject;
use App\Feature\Combat\Domain\Model\ConditionTerm;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\EffectDefinition;
use App\Feature\Combat\Domain\Model\EffectKind;
use App\Feature\Combat\Domain\Model\PlanRule;
use App\Feature\Combat\Domain\Model\TargetSelector;
use App\Feature\Combat\Domain\Model\Team;

/**
 * The scenarios pinned by the golden replay corpus.
 *
 * Shared between {@see \App\Tests\Unit\Feature\Combat\Domain\Engine\GoldenReplayTest}
 * and bin/regenerate-combat-goldens.php, so the fixtures can never drift from
 * the definitions that produced them.
 *
 * Each scenario targets a different part of resolution, so that a change to any
 * one of them shows up as a diff rather than passing unnoticed.
 */
final class GoldenScenarios
{
    private function __construct()
    {
    }

    /**
     * @return array<string, array{input: CombatInput, seed: int}>
     */
    public static function all(): array
    {
        return [
            'simple-duel' => ['input' => self::simpleDuel(), 'seed' => 0x0BADC0DE],
            'dodge-and-crit' => ['input' => self::dodgeAndCrit(), 'seed' => 0x51DE0001],
            'multi-target' => ['input' => self::multiTarget(), 'seed' => 0x7A9C0002],
            'effects' => ['input' => self::effects(), 'seed' => 0x3EFF0003],
            'stalemate-draw' => ['input' => self::stalemate(), 'seed' => 0x0DEA0004],
        ];
    }

    /** Baseline: two plain combatants, no randomness in play. */
    private static function simpleDuel(): CombatInput
    {
        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-warden', Team::Players, [
                'name' => 'Warden',
                'maxHealth' => 320,
                'weaponBaseDamage' => 34,
                'armourRating' => 480,
                'initiative' => 180,
            ]))
            ->withParticipant(CombatScenario::participant('b-blightling', Team::Enemies, [
                'name' => 'Blightling',
                'maxHealth' => 260,
                'weaponBaseDamage' => 28,
                'armourRating' => 220,
                'initiative' => 140,
            ]))
            ->build();
    }

    /** Exercises the hit and critical roll sites. */
    private static function dodgeAndCrit(): CombatInput
    {
        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-duellist', Team::Players, [
                'maxHealth' => 400,
                'weaponBaseDamage' => 30,
                'critChanceBp' => 3500,
                'critPowerBp' => 21000,
                'dodgeChanceBp' => 2200,
                'accuracyBp' => 600,
            ]))
            ->withParticipant(CombatScenario::participant('b-shade', Team::Enemies, [
                'maxHealth' => 380,
                'weaponBaseDamage' => 32,
                'critChanceBp' => 2000,
                'dodgeChanceBp' => 2500,
            ]))
            ->build();
    }

    /** Exercises AllEnemies, RandomEnemy and tie-breaking across four actors. */
    private static function multiTarget(): CombatInput
    {
        $sweep = new Ability(
            id: 'ability.sweeping_arc',
            localisationKey: 'ability.sweeping_arc',
            focusCost: 12,
            cooldownRounds: 2,
            damageCoefficientBp: 7000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::AllEnemies,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::EnemyCount,
                    ComparisonOperator::GreaterOrEqual,
                    2,
                )]),
                'ability.sweeping_arc',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withAbility($sweep)
            ->withParticipant(CombatScenario::participant('a-warden', Team::Players, [
                'abilityIds' => ['ability.strike', 'ability.sweeping_arc'],
                'battlePlan' => $plan,
                'maxHealth' => 500,
                'weaponBaseDamage' => 40,
                'maxFocus' => 40,
                'focusPerTurn' => 6,
                'initiative' => 200,
            ]))
            ->withParticipant(CombatScenario::participant('b-swarm-one', Team::Enemies, [
                'maxHealth' => 120,
                'weaponBaseDamage' => 14,
                'initiative' => 150,
            ]))
            ->withParticipant(CombatScenario::participant('c-swarm-two', Team::Enemies, [
                'maxHealth' => 120,
                'weaponBaseDamage' => 14,
                'initiative' => 150,
            ]))
            ->withParticipant(CombatScenario::participant('d-swarm-three', Team::Enemies, [
                'maxHealth' => 120,
                'weaponBaseDamage' => 14,
                'initiative' => 150,
            ]))
            ->build();
    }

    /** Exercises damage-over-time, healing and an outgoing damage modifier. */
    private static function effects(): CombatInput
    {
        $burning = new EffectDefinition(
            id: 'effect.burning',
            localisationKey: 'effect.burning',
            kind: EffectKind::DamageOverTime,
            magnitude: 9,
            durationRounds: 3,
            school: DamageSchool::Arcane,
        );

        $resolve = new EffectDefinition(
            id: 'effect.resolve',
            localisationKey: 'effect.resolve',
            kind: EffectKind::DamageModifier,
            magnitude: 2500,
            durationRounds: 4,
        );

        $ignite = new Ability(
            id: 'ability.ignite',
            localisationKey: 'ability.ignite',
            focusCost: 10,
            cooldownRounds: 4,
            damageCoefficientBp: 6000,
            healCoefficientBp: 0,
            school: DamageSchool::Arcane,
            selector: TargetSelector::LowestHealthEnemy,
            effectId: 'effect.burning',
            effectChanceBp: 8000,
        );

        $draught = new Ability(
            id: 'ability.emberdraught',
            localisationKey: 'ability.emberdraught',
            focusCost: 15,
            cooldownRounds: 3,
            damageCoefficientBp: 0,
            healCoefficientBp: 9000,
            school: DamageSchool::Nature,
            selector: TargetSelector::SelfOnly,
            effectId: 'effect.resolve',
            effectChanceBp: 10000,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::SelfHealthPercent,
                    ComparisonOperator::LessThan,
                    45,
                )]),
                'ability.emberdraught',
            ),
            new PlanRule(
                new Condition([ConditionTerm::hasEffect(ConditionSubject::TargetHasEffect, 'effect.burning')]),
                'ability.strike',
            ),
            new PlanRule(Condition::always(), 'ability.ignite'),
        ]);

        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withAbility($ignite)
            ->withAbility($draught)
            ->withEffect($burning)
            ->withEffect($resolve)
            ->withParticipant(CombatScenario::participant('a-emberwarden', Team::Players, [
                'abilityIds' => ['ability.strike', 'ability.ignite', 'ability.emberdraught'],
                'battlePlan' => $plan,
                'maxHealth' => 420,
                'weaponBaseDamage' => 26,
                'maxFocus' => 45,
                'focusPerTurn' => 7,
                'initiative' => 165,
                'resistanceRatings' => ['arcane' => 300],
            ]))
            ->withParticipant(CombatScenario::participant('b-blightbeast', Team::Enemies, [
                'maxHealth' => 600,
                'weaponBaseDamage' => 33,
                'armourRating' => 400,
                'initiative' => 120,
            ]))
            ->build();
    }

    /** Pins the round cap and the draw outcome. */
    private static function stalemate(): CombatInput
    {
        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-bulwark', Team::Players, [
                'maxHealth' => 900000,
                'weaponBaseDamage' => 1,
                'armourRating' => 90000,
                'scalingBp' => 10000,
            ]))
            ->withParticipant(CombatScenario::participant('b-monolith', Team::Enemies, [
                'maxHealth' => 900000,
                'weaponBaseDamage' => 1,
                'armourRating' => 90000,
            ]))
            ->build();
    }
}
