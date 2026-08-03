<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * The ten equipment slots. Structural — see docs/items.md section 2.
 */
enum EquipmentSlot: string
{
    case Head = 'Head';
    case Chest = 'Chest';
    case Legs = 'Legs';
    case Hands = 'Hands';
    case Feet = 'Feet';
    case MainHand = 'MainHand';
    case OffHand = 'OffHand';
    case Amulet = 'Amulet';
    case Ring1 = 'Ring1';
    case Ring2 = 'Ring2';

    /**
     * The slot's share of the armour curve, in basis points.
     *
     * Rings and amulets are zero on purpose: they carry no base stats at all
     * and are pure affix vehicles. That gives them a distinct role — build
     * customisation rather than raw power — and keeps the number of stat
     * sources bounded. See docs/items.md section 3.
     */
    public function armourWeightBp(): int
    {
        return match ($this) {
            self::Chest => 13000,
            self::OffHand => 12000,
            self::Legs => 11000,
            self::Head => 9000,
            self::Hands, self::Feet => 7000,
            self::Amulet, self::Ring1, self::Ring2, self::MainHand => 0,
        };
    }

    public function isWeaponSlot(): bool
    {
        return $this === self::MainHand || $this === self::OffHand;
    }

    /**
     * Ring1 and Ring2 accept the same definitions, so a ring dropped for one
     * may be worn in the other.
     */
    public function isRing(): bool
    {
        return $this === self::Ring1 || $this === self::Ring2;
    }

    /**
     * @return list<self>
     */
    public static function interchangeableWith(self $slot): array
    {
        return $slot->isRing() ? [self::Ring1, self::Ring2] : [$slot];
    }
}
