<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use App\Feature\Character\Domain\Model\Attribute;
use InvalidArgumentException;

/**
 * An item template, as authored in content.
 *
 * Instances reference this rather than copying it. If instances duplicated base
 * stats, every balance change to a base item would require a migration over
 * millions of rows, and old and new copies of the same item would silently
 * diverge. See docs/items.md section 1.
 */
final readonly class ItemDefinition
{
    /**
     * @param array<string, int> $attributeRequirements Keyed by Attribute value.
     * @param list<string>       $allowedAffixPools
     * @param list<string>       $tags
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public EquipmentSlot $slot,
        public int $itemLevel,
        public string $icon,
        public int $vendorValue,
        public int $requiredLevel,
        public array $attributeRequirements,
        public array $allowedAffixPools,
        public array $tags,
        public bool $twoHanded = false,
        public ?WeaponClass $weaponClass = null,
    ) {
        if ($twoHanded && $slot !== EquipmentSlot::MainHand) {
            throw new InvalidArgumentException(
                sprintf('Item "%s" is two-handed but does not occupy the main hand.', $id),
            );
        }

        if ($weaponClass !== null && !$slot->isWeaponSlot()) {
            throw new InvalidArgumentException(
                sprintf('Item "%s" declares a weapon class but is not a weapon.', $id),
            );
        }

        foreach ($attributeRequirements as $code => $value) {
            if (Attribute::tryFrom((string) $code) === null) {
                throw new InvalidArgumentException(sprintf('Unknown attribute requirement "%s".', (string) $code));
            }

            if ($value < 1) {
                throw new InvalidArgumentException('Attribute requirements must be positive.');
            }
        }
    }

    /**
     * Base weapon damage, derived from item level. Zero for anything that is
     * not a weapon.
     *
     * Item level is the only knob that scales an item's power with content
     * tier, which is what keeps the curve reviewable in one place.
     */
    public function baseWeaponDamage(): int
    {
        if ($this->weaponClass === null) {
            return 0;
        }

        return intdiv((8 + 4 * $this->itemLevel) * $this->weaponClass->damageWeightBp(), 10000);
    }

    /**
     * Base armour, derived from item level and the slot's share of the curve.
     */
    public function baseArmour(): int
    {
        return intdiv((5 + 3 * $this->itemLevel) * $this->slot->armourWeightBp(), 10000);
    }
}
