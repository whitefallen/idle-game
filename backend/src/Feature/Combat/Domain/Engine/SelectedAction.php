<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\Ability;

/**
 * The outcome of evaluating a battle plan for one actor on one turn.
 *
 * Carries the rule index so the log can state which rule fired, which is the
 * mechanism that makes the battle plan learnable rather than opaque.
 */
final readonly class SelectedAction
{
    /**
     * @param list<CombatantState> $targets
     */
    public function __construct(
        public int $ruleIndex,
        public Ability $ability,
        public array $targets,
    ) {
    }
}
