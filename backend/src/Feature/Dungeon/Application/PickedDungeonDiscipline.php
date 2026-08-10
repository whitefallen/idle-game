<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Dungeon\Domain\Entity\DungeonRun;

/**
 * The outcome of confirming a discipline pick, as the controller needs it.
 *
 * The post-pick character travels with the result, same reason every other
 * grant-bearing response in this codebase does: the client never has to
 * re-fetch to see the discipline become slottable.
 */
final readonly class PickedDungeonDiscipline
{
    public function __construct(
        public DungeonRun $run,
        public Character $character,
    ) {
    }
}
