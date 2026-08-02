<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * How an ability chooses what it acts on.
 *
 * A closed set rather than a scripting hook: targeting is evaluated inside the
 * request path for every actor on every round, so it must be constant-time and
 * incapable of failing. Every selector resolves deterministically; RandomEnemy
 * draws from the counter-based RNG under its own roll purpose.
 */
enum TargetSelector: string
{
    case LowestHealthEnemy = 'lowest_health_enemy';
    case HighestHealthEnemy = 'highest_health_enemy';
    case RandomEnemy = 'random_enemy';
    case AllEnemies = 'all_enemies';
    case LowestHealthAlly = 'lowest_health_ally';
    case SelfOnly = 'self';

    /**
     * Whether the selector resolves against the acting side rather than the
     * opposing one.
     */
    public function targetsOwnTeam(): bool
    {
        return match ($this) {
            self::LowestHealthAlly, self::SelfOnly => true,
            default => false,
        };
    }

    public function isMultiTarget(): bool
    {
        return $this === self::AllEnemies;
    }
}
