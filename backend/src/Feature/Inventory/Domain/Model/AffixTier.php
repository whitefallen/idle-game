<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use InvalidArgumentException;

final readonly class AffixTier
{
    public function __construct(
        public int $tier,
        public int $minimumItemLevel,
        public int $minimumRoll,
        public int $maximumRoll,
    ) {
        if ($minimumRoll > $maximumRoll) {
            throw new InvalidArgumentException(
                sprintf('Tier %d has an inverted roll range.', $tier),
            );
        }
    }

    public function rollSpread(): int
    {
        return $this->maximumRoll - $this->minimumRoll + 1;
    }
}
