<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * One possible outcome of a drop roll.
 *
 * "Nothing" is an explicit entry rather than a leftover probability, so the
 * chance of an empty result is visible in content review instead of being
 * implied by whatever the other weights fail to add up to.
 */
final readonly class DropEntry
{
    public function __construct(
        public int $weight,
        public DropEntryKind $kind,
        public ?string $materialId = null,
        public int $minimumQuantity = 0,
        public int $maximumQuantity = 0,
        public ?string $pool = null,
        public int $minimumItemLevel = 0,
        public int $maximumItemLevel = 0,
    ) {
    }
}
