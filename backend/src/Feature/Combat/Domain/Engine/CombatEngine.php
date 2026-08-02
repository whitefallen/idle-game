<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Combat\Domain\Model\EffectKind;
use App\Feature\Combat\Domain\Model\LogEvent;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\Team;
use App\Feature\Combat\Domain\Rng\DeterministicRng;
use App\Feature\Combat\Domain\Rng\RollPurpose;
use InvalidArgumentException;

/**
 * Resolves an encounter.
 *
 * A pure function of its arguments: no database, no clock, no container, no
 * filesystem, no static mutable state. Given the same input, seed and ruleset
 * version it returns an identical log forever, including after balance patches.
 *
 * This class must never acquire a constructor dependency. CombatEnginePurityTest
 * fails the build if it does — purity is not preserved by intent, it erodes one
 * convenient injection at a time.
 *
 * See docs/combat.md and ADR-0002.
 */
final class CombatEngine
{
    /**
     * Bumped by any change that alters resolution. Stored with every encounter
     * so historical fights are replayed under the rules they were fought under.
     */
    public const string RULESET_VERSION = '1.0.0';

    /**
     * Structural, not tunable. Without it, two sustain builds can loop forever,
     * which is an availability risk on a server that resolves fights inside a
     * request. Reaching the cap is a draw. See docs/combat.md section 4.
     */
    public const int MAX_ROUNDS = 50;

    public function resolve(CombatInput $input, int $seed): CombatLog
    {
        if ($input->rulesetVersion !== self::RULESET_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Input declares ruleset "%s" but this engine implements "%s". '
                . 'Replaying a historical encounter requires the engine version that produced it.',
                $input->rulesetVersion,
                self::RULESET_VERSION,
            ));
        }

        /** @var list<CombatantState> $states Ordered by ordinal, i.e. by participant id. */
        $states = [];
        foreach ($input->participants as $participant) {
            $states[] = new CombatantState($participant, $input->ordinalOf($participant->id));
        }

        $turnOrder = self::initiativeOrder($states);

        /** @var list<LogEvent> $events */
        $events = [];
        $outcome = null;
        $roundsElapsed = 0;

        for ($round = 1; $round <= self::MAX_ROUNDS; ++$round) {
            $roundsElapsed = $round;
            $events[] = LogEvent::roundStart($round);

            self::beginRound($states, $events);


            $outcome = self::terminalOutcome($states);
            if ($outcome !== null) {
                break;
            }

            foreach ($turnOrder as $actor) {
                if (!$actor->isAlive()) {
                    continue;
                }

                self::takeTurn($actor, $input, $states, $seed, $round, $events);

                $outcome = self::terminalOutcome($states);
                if ($outcome !== null) {
                    break;
                }
            }

            if ($outcome !== null) {
                break;
            }
        }

        $outcome ??= Outcome::Draw;
        $events[] = LogEvent::encounterEnd($outcome, $roundsElapsed);

        return new CombatLog(
            rulesetVersion: self::RULESET_VERSION,
            seed: $seed,
            participants: $input->participants,
            events: $events,
            outcome: $outcome,
            rounds: $roundsElapsed,
        );
    }

    /**
     * Turn order, computed once at encounter start.
     *
     * Ties break on ordinal, never on insertion order or wall-clock. This is a
     * determinism requirement rather than a gameplay choice.
     *
     * @param list<CombatantState> $states
     *
     * @return list<CombatantState>
     */
    private static function initiativeOrder(array $states): array
    {
        usort($states, static function (CombatantState $a, CombatantState $b): int {
            return $b->participant->initiative <=> $a->participant->initiative
                ?: $a->ordinal <=> $b->ordinal;
        });

        return array_values($states);
    }

    /**
     * Round start: tick periodic effects, regenerate Focus, then expire
     * finished effects.
     *
     * Cooldowns need no work here — they are stored as the absolute round from
     * which an ability becomes available again.
     *
     * Effects tick before their duration is decremented, so an effect with a
     * duration of three ticks exactly three times.
     *
     * @param list<CombatantState> $states
     * @param list<LogEvent>       $events
     */
    private static function beginRound(array $states, array &$events): void
    {
        foreach ($states as $state) {
            if (!$state->isAlive()) {
                continue;
            }

            foreach ($state->activeEffects() as $entry) {
                $effect = $entry['effect'];

                switch ($effect->kind) {
                    case EffectKind::DamageOverTime:
                        $state->applyDamage($effect->magnitude);
                        $events[] = LogEvent::effectTicked(
                            $state->participant->id,
                            $effect->id,
                            $effect->magnitude,
                            $state->health,
                        );

                        if (!$state->isAlive()) {
                            $events[] = LogEvent::died($state->participant->id);

                            break 2;
                        }

                        break;

                    case EffectKind::HealOverTime:
                        $restored = $state->applyHealing($effect->magnitude);
                        $events[] = LogEvent::effectTicked(
                            $state->participant->id,
                            $effect->id,
                            $restored,
                            $state->health,
                        );

                        break;

                    case EffectKind::DamageModifier:
                        // Read at damage time; nothing happens on tick.
                        break;
                }
            }
        }

        foreach ($states as $state) {
            if (!$state->isAlive()) {
                continue;
            }

            $state->regenerateFocus();
        }

        foreach ($states as $state) {
            foreach ($state->tickEffectDurations() as $expiredId) {
                $events[] = LogEvent::effectExpired($state->participant->id, $expiredId);
            }
        }
    }

    /**
     * @param list<CombatantState> $states
     * @param list<LogEvent>       $events
     */
    private static function takeTurn(
        CombatantState $actor,
        CombatInput $input,
        array $states,
        int $seed,
        int $round,
        array &$events,
    ): void {
        $action = PlanEvaluator::select($actor, $input, $states, $seed, $round);

        if ($action === null) {
            $events[] = LogEvent::planExhausted($actor->participant->id);

            return;
        }

        $ability = $action->ability;

        $events[] = LogEvent::planMatched(
            $actor->participant->id,
            // 1-based: the log is read by players against a numbered rule list.
            $action->ruleIndex + 1,
            $ability->id,
        );

        $actor->spendFocus($ability->focusCost);
        $actor->startCooldown($ability->id, $ability->cooldownRounds, $round);

        $modifierBp = $actor->damageModifierBp();

        foreach ($action->targets as $target) {
            $connected = true;

            if ($ability->damageCoefficientBp > 0) {
                $connected = self::resolveDamage(
                    $actor,
                    $target,
                    $ability,
                    $modifierBp,
                    $seed,
                    $round,
                    $events,
                );
            }

            if (!$connected || !$target->isAlive()) {
                continue;
            }

            if ($ability->healCoefficientBp > 0) {
                $restored = $target->applyHealing(
                    DamagePipeline::healing($actor->participant, $modifierBp, $ability),
                );

                $events[] = LogEvent::heal(
                    $actor->participant->id,
                    $target->participant->id,
                    $restored,
                    $target->health,
                );
            }

            if ($ability->effectId !== null) {
                $applied = DeterministicRng::chance(
                    $seed,
                    $round,
                    $actor->ordinal,
                    RollPurpose::EffectApplication,
                    $target->ordinal,
                    $ability->effectChanceBp,
                );

                if ($applied) {
                    $effect = $input->effect($ability->effectId);
                    $target->applyEffect($effect, $actor->participant->id);

                    $events[] = LogEvent::effectApplied(
                        $actor->participant->id,
                        $target->participant->id,
                        $effect->id,
                        $effect->durationRounds,
                    );
                }
            }
        }
    }

    /**
     * @param list<LogEvent> $events
     *
     * @return bool Whether the attack connected. A miss stops the ability's
     *              other facets from applying.
     */
    private static function resolveDamage(
        CombatantState $actor,
        CombatantState $target,
        Ability $ability,
        int $modifierBp,
        int $seed,
        int $round,
        array &$events,
    ): bool {
        $dodged = DeterministicRng::chance(
            $seed,
            $round,
            $actor->ordinal,
            RollPurpose::HitCheck,
            $target->ordinal,
            DamagePipeline::effectiveDodgeBp($actor->participant, $target->participant),
        );

        if ($dodged) {
            $events[] = LogEvent::miss($actor->participant->id, $target->participant->id);

            return false;
        }

        $critical = DeterministicRng::chance(
            $seed,
            $round,
            $actor->ordinal,
            RollPurpose::CriticalCheck,
            $target->ordinal,
            $actor->participant->critChanceBp,
        );

        $amount = DamagePipeline::damage(
            $actor->participant,
            $modifierBp,
            $target->participant,
            $ability,
            $critical,
        );

        $target->applyDamage($amount);

        $events[] = LogEvent::damage(
            $actor->participant->id,
            $target->participant->id,
            $amount,
            $critical,
            $ability->school,
            $target->health,
        );

        if (!$target->isAlive()) {
            $events[] = LogEvent::died($target->participant->id);
        }

        return true;
    }

    /**
     * @param list<CombatantState> $states
     */
    private static function terminalOutcome(array $states): ?Outcome
    {
        $playersAlive = false;
        $enemiesAlive = false;

        foreach ($states as $state) {
            if (!$state->isAlive()) {
                continue;
            }

            if ($state->participant->team === Team::Players) {
                $playersAlive = true;
            } else {
                $enemiesAlive = true;
            }
        }

        return match (true) {
            !$playersAlive && !$enemiesAlive => Outcome::Draw,
            !$enemiesAlive => Outcome::Victory,
            !$playersAlive => Outcome::Defeat,
            default => null,
        };
    }
}
