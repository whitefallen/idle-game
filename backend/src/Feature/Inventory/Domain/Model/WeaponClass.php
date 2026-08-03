<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use App\Feature\Character\Domain\Model\Attribute;

/**
 * A weapon's damage curve and which attribute scales it.
 *
 * The scaling attribute is what makes weapon choice a build decision rather
 * than a number comparison: a Strength character gains nothing from a light
 * blade, however high its base damage. See docs/items.md section 3.
 */
enum WeaponClass: string
{
    case Heavy = 'heavy';
    case Light = 'light';
    case Focus = 'focus';

    /** Multiplier on the base damage curve, in basis points. */
    public function damageWeightBp(): int
    {
        return match ($this) {
            // Slow: higher coefficient, lower initiative.
            self::Heavy => 13000,
            self::Light => 9000,
            self::Focus => 10000,
        };
    }

    public function scalingAttribute(): Attribute
    {
        return match ($this) {
            self::Heavy => Attribute::Strength,
            self::Light => Attribute::Dexterity,
            self::Focus => Attribute::Intelligence,
        };
    }
}
