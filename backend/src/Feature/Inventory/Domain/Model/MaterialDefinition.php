<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * A refinement material.
 *
 * Materials are not a currency: they have no vendor price in either direction,
 * so gold cannot be converted into refinement progress and material scarcity
 * cannot be bought around. See docs/economy.md section 1.
 *
 * A material is *producible* when it carries a production line — a rate and the
 * level that unlocks it. Not every material has one: drop-only materials are
 * what keep the idle layer from being self-sufficient, which is the
 * interdependence docs/idle.md section 1 is built on.
 */
final readonly class MaterialDefinition
{
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $tier,
        public string $icon,
        /** Units produced per hour by one Holding slot, or null if drop-only. */
        public ?int $ratePerHour = null,
        /** The character level at which this line may be assigned to a slot. */
        public ?int $productionUnlockLevel = null,
    ) {
    }

    public function isProducible(): bool
    {
        return $this->ratePerHour !== null;
    }

    public function isProducibleAt(int $level): bool
    {
        return $this->isProducible() && $level >= ($this->productionUnlockLevel ?? 1);
    }
}
