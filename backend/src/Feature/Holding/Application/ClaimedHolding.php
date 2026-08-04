<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Model\HoldingYield;

/**
 * The outcome of a claim, as the controller needs it.
 *
 * The post-claim character travels with the result so the client never has to
 * re-fetch to show its new gold — the same reason the encounter endpoint
 * returns one.
 */
final readonly class ClaimedHolding
{
    public function __construct(
        public Holding $holding,
        public Character $character,
        public HoldingYield $claimed,
    ) {
    }
}
