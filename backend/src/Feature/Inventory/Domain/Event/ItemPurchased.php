<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Event;

/**
 * Emitted after a character buys an item from the Vendor.
 *
 * The gold spend and the new item are applied synchronously inside the buying
 * transaction. This event carries the deferred half: quest progress,
 * achievements and the economy analytics that track the Vendor as a gold sink
 * (docs/economy.md section 3).
 *
 * Ids and primitives only, never entities. See ADR-0004.
 */
final readonly class ItemPurchased
{
    public const string NAME = 'item.purchased';

    public function __construct(
        public string $itemId,
        public string $characterId,
        public string $definitionId,
        public int $itemLevel,
        public string $rarity,
        public int $goldSpent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'characterId' => $this->characterId,
            'definitionId' => $this->definitionId,
            'itemLevel' => $this->itemLevel,
            'rarity' => $this->rarity,
            'goldSpent' => $this->goldSpent,
        ];
    }
}
