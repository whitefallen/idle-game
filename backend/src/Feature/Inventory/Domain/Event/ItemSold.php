<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Event;

/**
 * Emitted after a character sells an item to the Vendor for its vendorValue.
 *
 * The gold award and the item's removal are applied synchronously inside the
 * selling transaction. This event carries the deferred half: quest progress,
 * achievements and the economy analytics that track vendor sales as a faucet
 * (docs/economy.md section 2).
 *
 * Ids and primitives only, never entities. See ADR-0004.
 */
final readonly class ItemSold
{
    public const string NAME = 'item.sold';

    public function __construct(
        public string $itemId,
        public string $characterId,
        public string $definitionId,
        public int $goldAwarded,
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
            'goldAwarded' => $this->goldAwarded,
        ];
    }
}
