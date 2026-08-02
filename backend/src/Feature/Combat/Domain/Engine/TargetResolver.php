<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\TargetSelector;
use App\Feature\Combat\Domain\Rng\DeterministicRng;
use App\Feature\Combat\Domain\Rng\RollPurpose;

/**
 * Resolves an ability's target selector to concrete combatants.
 *
 * Every selector is total and deterministic. Ties are broken by ordinal, which
 * is derived from participant id, so the result never depends on iteration
 * order (determinism rule R3).
 */
final class TargetResolver
{
    private function __construct()
    {
    }

    /**
     * @param list<CombatantState> $allStates Ordered by ordinal.
     *
     * @return list<CombatantState> Empty when no legal target exists.
     */
    public static function resolve(
        TargetSelector $selector,
        CombatantState $actor,
        array $allStates,
        int $seed,
        int $round,
        int $rollIndex,
    ): array {
        if ($selector === TargetSelector::SelfOnly) {
            return [$actor];
        }

        $candidates = self::livingCandidates($selector, $actor, $allStates);

        if ($candidates === []) {
            return [];
        }

        return match ($selector) {
            TargetSelector::AllEnemies => $candidates,

            TargetSelector::LowestHealthEnemy,
            TargetSelector::LowestHealthAlly => [self::byHealth($candidates, lowest: true)],

            TargetSelector::HighestHealthEnemy => [self::byHealth($candidates, lowest: false)],

            TargetSelector::RandomEnemy => [
                $candidates[DeterministicRng::below(
                    $seed,
                    $round,
                    $actor->ordinal,
                    RollPurpose::TargetSelection,
                    $rollIndex,
                    count($candidates),
                )],
            ],

            TargetSelector::SelfOnly => [$actor],
        };
    }

    /**
     * @param list<CombatantState> $allStates
     *
     * @return list<CombatantState>
     */
    private static function livingCandidates(
        TargetSelector $selector,
        CombatantState $actor,
        array $allStates,
    ): array {
        $wantedTeam = $selector->targetsOwnTeam()
            ? $actor->participant->team
            : $actor->participant->team->opposing();

        $candidates = [];

        foreach ($allStates as $state) {
            if ($state->isAlive() && $state->participant->team === $wantedTeam) {
                $candidates[] = $state;
            }
        }

        return $candidates;
    }

    /**
     * @param non-empty-list<CombatantState> $candidates
     */
    private static function byHealth(array $candidates, bool $lowest): CombatantState
    {
        $best = $candidates[0];

        foreach ($candidates as $candidate) {
            $better = $lowest
                ? $candidate->health < $best->health
                : $candidate->health > $best->health;

            // Ordinal breaks ties, so equal health never depends on array order.
            if ($better || ($candidate->health === $best->health && $candidate->ordinal < $best->ordinal)) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
