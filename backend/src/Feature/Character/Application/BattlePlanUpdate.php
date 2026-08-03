<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Combat\Domain\Model\PlanIssue;

/**
 * The outcome of saving a battle plan.
 *
 * Carries warnings alongside the saved character, because a plan can be
 * accepted and still be worth commenting on — an unreachable rule is a mistake
 * the player should see, but not a reason to refuse their plan.
 */
final readonly class BattlePlanUpdate
{
    /**
     * @param list<PlanIssue> $warnings
     */
    public function __construct(
        public Character $character,
        public array $warnings,
    ) {
    }
}
