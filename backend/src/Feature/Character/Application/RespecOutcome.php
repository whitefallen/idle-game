<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Inventory\Domain\Entity\ItemInstance;

/**
 * The outcome of an attribute respec.
 *
 * Carries the unequipped items alongside the character because a respec can
 * take gear off as a side effect, and a side effect the player did not ask for
 * has to be reported rather than merely applied. The UI needs the list to say
 * what came off; without it the player sees empty slots and no explanation.
 */
final readonly class RespecOutcome
{
    /**
     * @param list<ItemInstance> $unequipped Items the reallocation invalidated. Usually empty.
     */
    public function __construct(
        public Character $character,
        public int $goldSpent,
        public array $unequipped,
    ) {
    }
}
