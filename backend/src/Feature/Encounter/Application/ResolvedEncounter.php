<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Encounter\Domain\Entity\Encounter;

/**
 * The result of resolving an encounter.
 *
 * Carries the live combat log so the response can include it without a second
 * decompression, and the character so the client receives its post-fight state
 * in the same payload — sparing the UI a follow-up request in the single most
 * frequently used endpoint in the game.
 */
final readonly class ResolvedEncounter
{
    /**
     * @param array{experience: int, gold: int, levelsGained: int, vigorRefunded: int} $rewards
     */
    public function __construct(
        public Encounter $encounter,
        public CombatLog $log,
        public Character $character,
        public array $rewards,
    ) {
    }
}
