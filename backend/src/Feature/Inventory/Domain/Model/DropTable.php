<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use InvalidArgumentException;

/**
 * A weighted table of possible rewards.
 *
 * Rarity is rolled separately from the item pool, so rarity distribution is
 * tuned in one place rather than duplicated across every table.
 * See docs/items.md section 8.
 */
final readonly class DropTable
{
    /**
     * @param list<DropEntry> $entries
     */
    public function __construct(
        public string $id,
        public int $rolls,
        public array $entries,
    ) {
        if ($entries === []) {
            throw new InvalidArgumentException(sprintf('Drop table "%s" has no entries.', $id));
        }

        if ($rolls < 1) {
            throw new InvalidArgumentException(sprintf('Drop table "%s" must roll at least once.', $id));
        }
    }

    /**
     * @return list<int>
     */
    public function weights(): array
    {
        return array_map(static fn (DropEntry $entry): int => $entry->weight, $this->entries);
    }
}
