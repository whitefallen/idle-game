<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * A fungible material.
 *
 * Most materials are refinement inputs: they have no vendor price in either
 * direction, so gold cannot be converted into refinement progress and material
 * scarcity cannot be bought around. See docs/economy.md section 1.
 *
 * A `kind: quest` material (e.g. a dungeon key) is not a refinement input and
 * deliberately carries no `tier` — a fungible that can never be selected for
 * refinement must not carry a tier that claims it can.
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
        public string $icon,
        /** Null only for a `kind: quest` material. */
        public ?int $tier = null,
        public string $kind = 'refinement',
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
