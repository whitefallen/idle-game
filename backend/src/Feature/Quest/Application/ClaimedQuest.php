<?php

declare(strict_types=1);

namespace App\Feature\Quest\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Quest\Domain\Entity\QuestRun;

/**
 * The outcome of a claim, as the controller needs it.
 *
 * `log` is null exactly when the claim hit content drift (the ruleset version
 * moved since accept) — there was no fight to log. See
 * {@see \App\Feature\Quest\Domain\Entity\QuestRun::markContentChanged()}.
 */
final readonly class ClaimedQuest
{
    public function __construct(
        public QuestRun $run,
        public ?CombatLog $log,
        public Character $character,
    ) {
    }
}
