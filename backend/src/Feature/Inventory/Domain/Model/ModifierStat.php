<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use App\Feature\Character\Domain\Model\Attribute;
use App\Feature\Combat\Domain\Model\DamageSchool;

/**
 * What an affix can modify.
 *
 * A closed set. An affix able to modify anything would make the stat budget in
 * docs/progression.md section 2.2 unenforceable, and that budget is the single
 * most important balancing constraint in the project.
 */
enum ModifierStat: string
{
    case ArmourValue = 'armourValue';
    case WeaponBaseDamage = 'weaponBaseDamage';
    case FlatDamage = 'flatDamage';

    case Strength = 'STR';
    case Dexterity = 'DEX';
    case Intelligence = 'INT';
    case Constitution = 'CON';
    case Luck = 'LUK';

    case CritChanceBp = 'critChanceBp';
    case CritPowerBp = 'critPowerBp';
    case DodgeChanceBp = 'dodgeChanceBp';
    case AccuracyBp = 'accuracyBp';

    case ResistPhysical = 'resistPhysical';
    case ResistArcane = 'resistArcane';
    case ResistNature = 'resistNature';

    /**
     * The attribute this modifies, when it modifies one.
     */
    public function attribute(): ?Attribute
    {
        return match ($this) {
            self::Strength => Attribute::Strength,
            self::Dexterity => Attribute::Dexterity,
            self::Intelligence => Attribute::Intelligence,
            self::Constitution => Attribute::Constitution,
            self::Luck => Attribute::Luck,
            default => null,
        };
    }

    public function damageSchool(): ?DamageSchool
    {
        return match ($this) {
            self::ResistPhysical => DamageSchool::Physical,
            self::ResistArcane => DamageSchool::Arcane,
            self::ResistNature => DamageSchool::Nature,
            default => null,
        };
    }

    /**
     * Whether a percentage modifier is meaningful. Percentages apply to the
     * item's own base value, so only stats an item has a base for qualify.
     */
    public function supportsPercent(): bool
    {
        return $this === self::ArmourValue || $this === self::WeaponBaseDamage;
    }
}
