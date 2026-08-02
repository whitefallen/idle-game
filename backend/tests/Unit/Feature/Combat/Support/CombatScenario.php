<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Support;

use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\EffectDefinition;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;
use App\Feature\Combat\Domain\Model\TargetSelector;

/**
 * Builds combat inputs for tests.
 *
 * Exists so that a test can state only the property it cares about — "a
 * defender with 5000 armour", "an attacker who always crits" — rather than
 * restating twenty stat fields each time. Defaults are deliberately boring:
 * no dodge, no crit, no resistance, so any randomness a test observes is
 * randomness it asked for.
 */
final class CombatScenario
{
    /** @var list<Participant> */
    private array $participants = [];

    /** @var array<string, Ability> */
    private array $abilities = [];

    /** @var array<string, EffectDefinition> */
    private array $effects = [];

    public static function create(): self
    {
        return new self();
    }

    public function withAbility(Ability $ability): self
    {
        $this->abilities[$ability->id] = $ability;

        return $this;
    }

    public function withEffect(EffectDefinition $effect): self
    {
        $this->effects[$effect->id] = $effect;

        return $this;
    }

    public function withParticipant(Participant $participant): self
    {
        $this->participants[] = $participant;

        return $this;
    }

    public function build(): CombatInput
    {
        return new CombatInput(
            $this->participants,
            $this->abilities,
            $this->effects,
            CombatEngine::RULESET_VERSION,
        );
    }

    /**
     * A zero-cost, no-cooldown attack. Valid as a plan's guaranteed fallback.
     */
    public static function basicStrike(
        string $id = 'ability.strike',
        int $damageCoefficientBp = 10000,
        TargetSelector $selector = TargetSelector::LowestHealthEnemy,
    ): Ability {
        return new Ability(
            id: $id,
            localisationKey: 'ability.' . $id,
            focusCost: 0,
            cooldownRounds: 0,
            damageCoefficientBp: $damageCoefficientBp,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: $selector,
        );
    }

    /**
     * @param array<string, int|string|BattlePlan|Team|list<string>|array<string, int>> $overrides
     */
    public static function participant(string $id, Team $team, array $overrides = []): Participant
    {
        $abilityIds = $overrides['abilityIds'] ?? ['ability.strike'];
        \assert(\is_array($abilityIds));

        $plan = $overrides['battlePlan'] ?? BattlePlan::singleAbility((string) $abilityIds[0]);
        \assert($plan instanceof BattlePlan);

        $resistances = $overrides['resistanceRatings'] ?? [];
        \assert(\is_array($resistances));

        return new Participant(
            id: $id,
            definitionId: (string) ($overrides['definitionId'] ?? 'test.dummy'),
            name: (string) ($overrides['name'] ?? $id),
            team: $team,
            level: (int) ($overrides['level'] ?? 10),
            maxHealth: (int) ($overrides['maxHealth'] ?? 200),
            initiative: (int) ($overrides['initiative'] ?? 100),
            maxFocus: (int) ($overrides['maxFocus'] ?? 50),
            focusPerTurn: (int) ($overrides['focusPerTurn'] ?? 5),
            weaponBaseDamage: (int) ($overrides['weaponBaseDamage'] ?? 20),
            flatDamageBonus: (int) ($overrides['flatDamageBonus'] ?? 0),
            scalingBp: (int) ($overrides['scalingBp'] ?? 10000),
            critChanceBp: (int) ($overrides['critChanceBp'] ?? 0),
            critPowerBp: (int) ($overrides['critPowerBp'] ?? 15000),
            dodgeChanceBp: (int) ($overrides['dodgeChanceBp'] ?? 0),
            accuracyBp: (int) ($overrides['accuracyBp'] ?? 0),
            armourRating: (int) ($overrides['armourRating'] ?? 0),
            resistanceRatings: $resistances,
            battlePlan: $plan,
            abilityIds: array_values($abilityIds),
        );
    }

    /**
     * The common case: one player, one enemy, one ability, no randomness.
     */
    public static function duel(): CombatInput
    {
        return self::create()
            ->withAbility(self::basicStrike())
            ->withParticipant(self::participant('a-player', Team::Players))
            ->withParticipant(self::participant('b-enemy', Team::Enemies))
            ->build();
    }
}
