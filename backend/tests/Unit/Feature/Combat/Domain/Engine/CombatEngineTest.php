<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Engine\CombatEngine;
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
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\PlanRule;
use App\Feature\Combat\Domain\Model\TargetSelector;
use App\Feature\Combat\Domain\Model\Team;
use App\Tests\Unit\Feature\Combat\Support\CombatScenario;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CombatEngine::class)]
final class CombatEngineTest extends TestCase
{
    private CombatEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CombatEngine();
    }

    // ---------------------------------------------------------------
    // Determinism
    // ---------------------------------------------------------------

    public function testIdenticalInputsProduceIdenticalLogs(): void
    {
        $expected = null;

        for ($i = 0; $i < 200; ++$i) {
            $log = $this->engine->resolve(CombatScenario::duel(), 0x1234ABCD)->toArray();

            $expected ??= $log;
            self::assertSame($expected, $log);
        }
    }

    /**
     * Catches violations of determinism rule R3 — reliance on iteration order.
     *
     * The same participants are supplied in every possible order; because
     * CombatInput sorts by id, every permutation must resolve identically. A
     * `foreach` over an insertion-ordered structure anywhere in the engine
     * would show up here.
     */
    public function testParticipantOrderDoesNotAffectTheOutcome(): void
    {
        $ability = CombatScenario::basicStrike();

        $participants = [
            CombatScenario::participant('a-player', Team::Players, ['initiative' => 100]),
            CombatScenario::participant('b-player', Team::Players, ['initiative' => 100]),
            CombatScenario::participant('c-enemy', Team::Enemies, ['initiative' => 100]),
            CombatScenario::participant('d-enemy', Team::Enemies, ['initiative' => 100]),
        ];

        $reference = null;

        foreach ($this->permutations($participants) as $ordering) {
            $scenario = CombatScenario::create()->withAbility($ability);

            foreach ($ordering as $participant) {
                $scenario->withParticipant($participant);
            }

            $log = $this->engine->resolve($scenario->build(), 999)->toArray();

            $reference ??= $log;
            self::assertSame($reference, $log, 'Input ordering must not change resolution.');
        }
    }

    public function testDifferentSeedsProduceDifferentFights(): void
    {
        $input = static fn (): CombatInput => CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, ['dodgeChanceBp' => 5000]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, ['dodgeChanceBp' => 5000]))
            ->build();

        $logs = [];
        for ($seed = 1; $seed <= 20; ++$seed) {
            $logs[] = json_encode($this->engine->resolve($input(), $seed)->toArray());
        }

        self::assertGreaterThan(10, count(array_unique($logs)), 'Seeds should meaningfully diverge.');
    }

    // ---------------------------------------------------------------
    // Termination and invariants
    // ---------------------------------------------------------------

    /**
     * The engine must terminate for arbitrary inputs. Combined with the damage
     * floor of 1, the round cap makes this structural.
     */
    public function testAlwaysTerminatesWithinTheRoundCap(): void
    {
        mt_srand(4242);

        for ($i = 0; $i < 300; ++$i) {
            $log = $this->engine->resolve($this->randomisedDuel(), mt_rand());

            self::assertLessThanOrEqual(CombatEngine::MAX_ROUNDS, $log->rounds);
            self::assertGreaterThanOrEqual(1, $log->rounds);
        }
    }

    public function testHealthNeverGoesNegativeAndDamageIsNeverBelowOne(): void
    {
        mt_srand(77);

        for ($i = 0; $i < 200; ++$i) {
            foreach ($this->engine->resolve($this->randomisedDuel(), mt_rand())->events as $event) {
                if ($event->type === 'damage') {
                    self::assertGreaterThanOrEqual(1, $event->data['amount']);
                }

                if (isset($event->data['targetHealth'])) {
                    self::assertGreaterThanOrEqual(0, $event->data['targetHealth']);
                }
            }
        }
    }

    /**
     * Two mutually invincible combatants must draw at the cap rather than loop.
     */
    public function testUnwinnableFightDrawsAtTheRoundCap(): void
    {
        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'maxHealth' => 1_000_000,
                'weaponBaseDamage' => 1,
                'armourRating' => 100_000,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => 1_000_000,
                'weaponBaseDamage' => 1,
                'armourRating' => 100_000,
            ]))
            ->build();

        $log = $this->engine->resolve($input, 1);

        self::assertSame(Outcome::Draw, $log->outcome);
        self::assertSame(CombatEngine::MAX_ROUNDS, $log->rounds);
    }

    public function testEveryLogEndsWithExactlyOneEncounterEndEvent(): void
    {
        mt_srand(11);

        for ($i = 0; $i < 100; ++$i) {
            $events = $this->engine->resolve($this->randomisedDuel(), mt_rand())->events;
            $endEvents = array_filter($events, static fn ($e): bool => $e->type === 'encounter.end');

            self::assertCount(1, $endEvents);
            self::assertSame('encounter.end', $events[count($events) - 1]->type);
        }
    }

    // ---------------------------------------------------------------
    // Outcomes
    // ---------------------------------------------------------------

    public function testOverwhelmingPlayerWins(): void
    {
        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'weaponBaseDamage' => 500,
                'initiative' => 999,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, ['maxHealth' => 100]))
            ->build();

        $log = $this->engine->resolve($input, 7);

        self::assertSame(Outcome::Victory, $log->outcome);
        self::assertSame(1, $log->rounds);
    }

    public function testOverwhelmingEnemyDefeatsThePlayer(): void
    {
        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, ['maxHealth' => 50]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'weaponBaseDamage' => 500,
                'initiative' => 999,
            ]))
            ->build();

        self::assertSame(Outcome::Defeat, $this->engine->resolve($input, 7)->outcome);
    }

    public function testHigherInitiativeActsFirst(): void
    {
        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, ['initiative' => 1]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, ['initiative' => 500]))
            ->build();

        $log = $this->engine->resolve($input, 3);

        $firstAction = null;
        foreach ($log->events as $event) {
            if ($event->type === 'plan.matched') {
                $firstAction = $event->data['actor'];

                break;
            }
        }

        self::assertSame('b-enemy', $firstAction);
    }

    // ---------------------------------------------------------------
    // Battle plan behaviour
    // ---------------------------------------------------------------

    /**
     * The legibility requirement: every action records which rule fired, so a
     * player can see why their plan behaved as it did.
     */
    public function testEveryActionRecordsTheRuleThatFired(): void
    {
        $log = $this->engine->resolve(CombatScenario::duel(), 5);

        $matched = array_filter($log->events, static fn ($e): bool => $e->type === 'plan.matched');

        self::assertNotEmpty($matched);

        foreach ($matched as $event) {
            self::assertArrayHasKey('rule', $event->data);
            self::assertGreaterThanOrEqual(1, $event->data['rule'], 'Rule numbers are 1-based for players.');
            self::assertArrayHasKey('ability', $event->data);
        }
    }

    public function testHigherPriorityRuleFiresWhenItsConditionHolds(): void
    {
        $finisher = new Ability(
            id: 'ability.finisher',
            localisationKey: 'ability.finisher',
            focusCost: 0,
            cooldownRounds: 0,
            damageCoefficientBp: 30000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::TargetHealthPercent,
                    ComparisonOperator::LessOrEqual,
                    50,
                )]),
                'ability.finisher',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withAbility($finisher)
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'initiative' => 999,
                'abilityIds' => ['ability.strike', 'ability.finisher'],
                'battlePlan' => $plan,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => 100,
                'weaponBaseDamage' => 1,
            ]))
            ->build();

        $abilitiesUsedByPlayer = [];
        foreach ($this->engine->resolve($input, 42)->events as $event) {
            if ($event->type === 'plan.matched' && $event->data['actor'] === 'a-player') {
                $abilitiesUsedByPlayer[] = $event->data['ability'];
            }
        }

        // Enemy starts at full health, so the fallback fires first; once the
        // strike brings it to or below half, the conditional rule takes over.
        self::assertSame('ability.strike', $abilitiesUsedByPlayer[0]);
        self::assertContains('ability.finisher', $abilitiesUsedByPlayer);
    }

    public function testUnaffordableRuleFallsThroughToTheNextOne(): void
    {
        $expensive = new Ability(
            id: 'ability.expensive',
            localisationKey: 'ability.expensive',
            focusCost: 999,
            cooldownRounds: 0,
            damageCoefficientBp: 50000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::Round,
                    ComparisonOperator::GreaterOrEqual,
                    1,
                )]),
                'ability.expensive',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withAbility($expensive)
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'maxFocus' => 10,
                'abilityIds' => ['ability.strike', 'ability.expensive'],
                'battlePlan' => $plan,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies))
            ->build();

        foreach ($this->engine->resolve($input, 8)->events as $event) {
            if ($event->type === 'plan.matched' && $event->data['actor'] === 'a-player') {
                self::assertSame('ability.strike', $event->data['ability']);
            }
        }
    }

    public function testCooldownPreventsConsecutiveUse(): void
    {
        $burst = new Ability(
            id: 'ability.burst',
            localisationKey: 'ability.burst',
            focusCost: 0,
            cooldownRounds: 3,
            damageCoefficientBp: 20000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::Round,
                    ComparisonOperator::GreaterOrEqual,
                    1,
                )]),
                'ability.burst',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withAbility($burst)
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'abilityIds' => ['ability.strike', 'ability.burst'],
                'battlePlan' => $plan,
                'weaponBaseDamage' => 1,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => 100000,
                'weaponBaseDamage' => 1,
            ]))
            ->build();

        $roundsUsingBurst = [];
        $round = 0;

        foreach ($this->engine->resolve($input, 8)->events as $event) {
            if ($event->type === 'round.start') {
                $round = $event->data['round'];
            }

            if ($event->type === 'plan.matched'
                && $event->data['actor'] === 'a-player'
                && $event->data['ability'] === 'ability.burst'
            ) {
                $roundsUsingBurst[] = $round;
            }
        }

        self::assertGreaterThan(2, count($roundsUsingBurst));

        for ($i = 1; $i < count($roundsUsingBurst); ++$i) {
            self::assertGreaterThanOrEqual(
                4,
                $roundsUsingBurst[$i] - $roundsUsingBurst[$i - 1],
                'A three-round cooldown means at least four rounds between uses.',
            );
        }
    }

    /**
     * Regression: a countdown decremented at round start blocked for one round
     * fewer than declared, which made a one-round cooldown do nothing at all.
     */
    public function testSingleRoundCooldownSkipsExactlyOneRound(): void
    {
        $alternate = new Ability(
            id: 'ability.alternate',
            localisationKey: 'ability.alternate',
            focusCost: 0,
            cooldownRounds: 1,
            damageCoefficientBp: 10000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(
                    ConditionSubject::Round,
                    ComparisonOperator::GreaterOrEqual,
                    1,
                )]),
                'ability.alternate',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike('ability.strike', 1))
            ->withAbility($alternate)
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'abilityIds' => ['ability.strike', 'ability.alternate'],
                'battlePlan' => $plan,
                'weaponBaseDamage' => 1,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => 100000,
                'weaponBaseDamage' => 1,
            ]))
            ->build();

        $used = [];
        foreach ($this->engine->resolve($input, 13)->events as $event) {
            if ($event->type === 'plan.matched' && $event->data['actor'] === 'a-player') {
                $used[] = $event->data['ability'];
            }
        }

        // Strict alternation: the cooldown must force the fallback every
        // other round.
        self::assertGreaterThan(6, count($used));
        self::assertSame('ability.alternate', $used[0]);
        self::assertSame('ability.strike', $used[1]);
        self::assertSame('ability.alternate', $used[2]);
        self::assertSame('ability.strike', $used[3]);
    }

    // ---------------------------------------------------------------
    // Effects
    // ---------------------------------------------------------------

    public function testDamageOverTimeTicksExactlyForItsDuration(): void
    {
        $burning = new EffectDefinition(
            id: 'effect.burning',
            localisationKey: 'effect.burning',
            kind: EffectKind::DamageOverTime,
            magnitude: 5,
            durationRounds: 3,
        );

        $igniter = new Ability(
            id: 'ability.ignite',
            localisationKey: 'ability.ignite',
            focusCost: 0,
            cooldownRounds: 99,
            damageCoefficientBp: 100,
            healCoefficientBp: 0,
            school: DamageSchool::Arcane,
            selector: TargetSelector::LowestHealthEnemy,
            effectId: 'effect.burning',
            effectChanceBp: 10000,
        );

        $plan = new BattlePlan([
            new PlanRule(
                new Condition([ConditionTerm::numeric(ConditionSubject::Round, ComparisonOperator::Equal, 1)]),
                'ability.ignite',
            ),
            new PlanRule(Condition::always(), 'ability.strike'),
        ]);

        $input = CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike('ability.strike', 1))
            ->withAbility($igniter)
            ->withEffect($burning)
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'abilityIds' => ['ability.strike', 'ability.ignite'],
                'battlePlan' => $plan,
                'weaponBaseDamage' => 1,
                'initiative' => 999,
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => 100000,
                'weaponBaseDamage' => 1,
            ]))
            ->build();

        $log = $this->engine->resolve($input, 21);

        $ticks = array_filter(
            $log->events,
            static fn ($e): bool => $e->type === 'effect.ticked' && $e->data['effect'] === 'effect.burning',
        );

        $expiries = array_filter(
            $log->events,
            static fn ($e): bool => $e->type === 'effect.expired' && $e->data['effect'] === 'effect.burning',
        );

        self::assertCount(3, $ticks, 'A three-round effect must tick exactly three times.');
        self::assertCount(1, $expiries);

        foreach ($ticks as $tick) {
            self::assertSame(5, $tick->data['amount']);
        }
    }

    // ---------------------------------------------------------------
    // Ruleset versioning
    // ---------------------------------------------------------------

    public function testRefusesInputFromADifferentRuleset(): void
    {
        $input = new CombatInput(
            [
                CombatScenario::participant('a-player', Team::Players),
                CombatScenario::participant('b-enemy', Team::Enemies),
            ],
            ['ability.strike' => CombatScenario::basicStrike()],
            [],
            '0.9.0',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ruleset/');

        $this->engine->resolve($input, 1);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function randomisedDuel(): CombatInput
    {
        return CombatScenario::create()
            ->withAbility(CombatScenario::basicStrike())
            ->withParticipant(CombatScenario::participant('a-player', Team::Players, [
                'maxHealth' => mt_rand(20, 2000),
                'weaponBaseDamage' => mt_rand(1, 120),
                'armourRating' => mt_rand(0, 4000),
                'dodgeChanceBp' => mt_rand(0, 2500),
                'critChanceBp' => mt_rand(0, 5000),
                'initiative' => mt_rand(1, 400),
                'level' => mt_rand(1, 60),
            ]))
            ->withParticipant(CombatScenario::participant('b-enemy', Team::Enemies, [
                'maxHealth' => mt_rand(20, 2000),
                'weaponBaseDamage' => mt_rand(1, 120),
                'armourRating' => mt_rand(0, 4000),
                'dodgeChanceBp' => mt_rand(0, 2500),
                'critChanceBp' => mt_rand(0, 5000),
                'initiative' => mt_rand(1, 400),
                'level' => mt_rand(1, 60),
            ]))
            ->build();
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<list<mixed>>
     */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];

        foreach ($items as $index => $item) {
            $rest = $items;
            array_splice($rest, $index, 1);

            foreach ($this->permutations($rest) as $permutation) {
                $result[] = [$item, ...$permutation];
            }
        }

        return $result;
    }
}
