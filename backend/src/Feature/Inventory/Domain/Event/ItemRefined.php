<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Event;

/**
 * Emitted after an item's refinement level has advanced.
 *
 * The gold spend, the material spend and the level itself are applied
 * synchronously inside the refining transaction — a player would immediately
 * notice a wrong balance or a stat that did not move. This event carries the
 * deferred half: quest progress, achievements and the economy analytics that
 * track refinement as a gold and material sink (docs/economy.md).
 *
 * Ids and primitives only, never entities. See ADR-0004.
 */
final readonly class ItemRefined
{
    public const string NAME = 'item.refined';

    public function __construct(
        public string $itemId,
        public string $characterId,
        public int $fromLevel,
        public int $toLevel,
        public int $goldSpent,
        public string $materialId,
        public int $materialSpent,
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
            'fromLevel' => $this->fromLevel,
            'toLevel' => $this->toLevel,
            'goldSpent' => $this->goldSpent,
            'materialId' => $this->materialId,
            'materialSpent' => $this->materialSpent,
        ];
    }
}
