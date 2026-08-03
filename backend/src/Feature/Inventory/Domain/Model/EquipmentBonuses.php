<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use App\Feature\Character\Domain\Model\Attribute;
use App\Feature\Combat\Domain\Model\DamageSchool;

/**
 * Everything a character's equipped items contribute.
 *
 * Kept separate from the character's allocated attributes so that unequipping
 * can never leave the character in an invalid state: allocation is stored,
 * equipment is summed on read, and the two are only combined when derived
 * stats are computed. See docs/data-model.md section 2.
 */
final readonly class EquipmentBonuses
{
    /**
     * @param array<string, int> $attributes  Keyed by Attribute value.
     * @param array<string, int> $resistances Keyed by DamageSchool value.
     */
    public function __construct(
        public array $attributes = [],
        public int $armourValue = 0,
        public int $weaponBaseDamage = 0,
        public int $flatDamage = 0,
        public int $critChanceBp = 0,
        public int $critPowerBp = 0,
        public int $dodgeChanceBp = 0,
        public int $accuracyBp = 0,
        public array $resistances = [],
        /**
         * Which attribute scales damage. Null when nothing is equipped in the
         * main hand, in which case the unarmed baseline applies.
         */
        public ?Attribute $scalingAttribute = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function attribute(Attribute $attribute): int
    {
        return $this->attributes[$attribute->value] ?? 0;
    }

    public function resistance(DamageSchool $school): int
    {
        return $this->resistances[$school->value] ?? 0;
    }

    public function hasWeapon(): bool
    {
        return $this->scalingAttribute !== null;
    }
}
