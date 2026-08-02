<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\ConditionSubject;
use App\Feature\Combat\Domain\Model\ConditionTerm;

/**
 * Selects the action an actor takes, by walking its battle plan top-down.
 *
 * A rule fires when its condition holds, its ability is off cooldown, its Focus
 * cost is affordable, and it has a legal target. The first such rule wins.
 *
 * Each rule resolves its prospective target using its own roll index, so the
 * randomness consumed by rule 3 is independent of whether rule 1 or 2 was
 * evaluated first. This is only sound because randomness is counter-based; with
 * a sequential generator, skipping a rule would shift every later draw.
 */
final class PlanEvaluator
{
    private function __construct()
    {
    }

    /**
     * @param list<CombatantState> $allStates
     */
    public static function select(
        CombatantState $actor,
        CombatInput $input,
        array $allStates,
        int $seed,
        int $round,
    ): ?SelectedAction {
        foreach ($actor->participant->battlePlan->rules as $index => $rule) {
            $ability = $input->ability($rule->abilityId);

            if ($actor->isOnCooldown($ability->id, $round)) {
                continue;
            }

            if (!$actor->canAfford($ability->focusCost)) {
                continue;
            }

            $targets = TargetResolver::resolve(
                $ability->selector,
                $actor,
                $allStates,
                $seed,
                $round,
                $index,
            );

            if ($targets === []) {
                continue;
            }

            if (!self::holds($rule->condition, $actor, $targets[0], $allStates, $round)) {
                continue;
            }

            return new SelectedAction($index, $ability, $targets);
        }

        return null;
    }

    /**
     * @param list<CombatantState> $allStates
     */
    private static function holds(
        Condition $condition,
        CombatantState $actor,
        CombatantState $primaryTarget,
        array $allStates,
        int $round,
    ): bool {
        foreach ($condition->terms as $term) {
            if (!self::termHolds($term, $actor, $primaryTarget, $allStates, $round)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<CombatantState> $allStates
     */
    private static function termHolds(
        ConditionTerm $term,
        CombatantState $actor,
        CombatantState $primaryTarget,
        array $allStates,
        int $round,
    ): bool {
        return match ($term->subject) {
            ConditionSubject::Always => true,

            ConditionSubject::SelfHasEffect => $actor->hasEffect((string) $term->effectId),

            ConditionSubject::TargetHasEffect => $primaryTarget->hasEffect((string) $term->effectId),

            ConditionSubject::SelfHealthPercent => $term->operator?->compare(
                $actor->healthPercent(),
                (int) $term->value,
            ) ?? false,

            ConditionSubject::SelfFocus => $term->operator?->compare(
                $actor->focus,
                (int) $term->value,
            ) ?? false,

            ConditionSubject::TargetHealthPercent => $term->operator?->compare(
                $primaryTarget->healthPercent(),
                (int) $term->value,
            ) ?? false,

            ConditionSubject::EnemyCount => $term->operator?->compare(
                self::countLivingOpponents($actor, $allStates),
                (int) $term->value,
            ) ?? false,

            ConditionSubject::Round => $term->operator?->compare($round, (int) $term->value) ?? false,
        };
    }

    /**
     * @param list<CombatantState> $allStates
     */
    private static function countLivingOpponents(CombatantState $actor, array $allStates): int
    {
        $opposing = $actor->participant->team->opposing();
        $count = 0;

        foreach ($allStates as $state) {
            if ($state->isAlive() && $state->participant->team === $opposing) {
                ++$count;
            }
        }

        return $count;
    }
}
