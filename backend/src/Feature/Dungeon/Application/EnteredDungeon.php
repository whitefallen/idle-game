<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Dungeon\Domain\Entity\DungeonRun;

/**
 * The outcome of a dungeon run, as the controller needs it.
 *
 * The post-run character travels with the result, same reason Encounter and
 * Holding return one: the client never has to re-fetch to show new
 * experience and gold.
 */
final readonly class EnteredDungeon
{
    public function __construct(
        public DungeonRun $run,
        public Character $character,
    ) {
    }
}
