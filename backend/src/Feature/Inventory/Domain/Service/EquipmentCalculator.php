<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\AffixDefinition;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;
use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\ModifierMode;
use App\Feature\Inventory\Domain\Model\ModifierStat;

/**
 * Sums a character's equipped items into a single set of bonuses.
 *
 * Modifier application order is fixed: flat modifiers first, then percentages,
 * both sorted by affix id. Fixing the order matters because a percentage that
 * lands before a flat bonus produces a different number, and a player comparing
 * two items must be able to predict which is better. See docs/items.md §4.1.
 */
final class EquipmentCalculator
{
    private function __construct()
    {
    }

    /**
     * @param list<ItemInstance>             $equipped
     * @param array<string, ItemDefinition>  $definitions
     * @param array<string, AffixDefinition> $affixes
     */
    public static function sum(array $equipped, array $definitions, array $affixes): EquipmentBonuses
    {
        $attributes = [];
        $resistances = [];

        $armour = 0;
        $weaponDamage = 0;
        $flatDamage = 0;
        $critChance = 0;
        $critPower = 0;
        $dodge = 0;
        $accuracy = 0;
        $scaling = null;

        // Ordered by slot so the sum does not depend on how the rows came back
        // from the database.
        usort(
            $equipped,
            static function (ItemInstance $a, ItemInstance $b): int {
                $left = $a->equippedSlot();
                $right = $b->equippedSlot();

                return strcmp($left === null ? '' : $left->value, $right === null ? '' : $right->value);
            },
        );

        foreach ($equipped as $item) {
            $definition = $definitions[$item->definitionId()] ?? null;

            if ($definition === null) {
                // A retired definition. Content is append-only, so this should
                // not happen; skipping is safer than throwing on a read path.
                continue;
            }

            $itemArmour = $definition->baseArmour();
            $itemDamage = $definition->baseWeaponDamage();

            $armourPercentBp = 0;
            $damagePercentBp = 0;

            foreach (self::orderedAffixes($item, $affixes) as [$affix, $value]) {
                if ($affix->mode === ModifierMode::Percent) {
                    // Collected, not applied yet: percentages act on the flat
                    // total, so they must wait until the flats are summed.
                    match ($affix->stat) {
                        ModifierStat::ArmourValue => $armourPercentBp += $value,
                        ModifierStat::WeaponBaseDamage => $damagePercentBp += $value,
                        default => null,
                    };

                    continue;
                }

                $attribute = $affix->stat->attribute();

                if ($attribute !== null) {
                    $attributes[$attribute->value] = ($attributes[$attribute->value] ?? 0) + $value;

                    continue;
                }

                $school = $affix->stat->damageSchool();

                if ($school !== null) {
                    $resistances[$school->value] = ($resistances[$school->value] ?? 0) + $value;

                    continue;
                }

                match ($affix->stat) {
                    ModifierStat::ArmourValue => $itemArmour += $value,
                    ModifierStat::WeaponBaseDamage => $itemDamage += $value,
                    ModifierStat::FlatDamage => $flatDamage += $value,
                    ModifierStat::CritChanceBp => $critChance += $value,
                    ModifierStat::CritPowerBp => $critPower += $value,
                    ModifierStat::DodgeChanceBp => $dodge += $value,
                    ModifierStat::AccuracyBp => $accuracy += $value,
                    default => null,
                };
            }

            $armour += intdiv($itemArmour * (10000 + $armourPercentBp), 10000);
            $weaponDamage += intdiv($itemDamage * (10000 + $damagePercentBp), 10000);

            // The main hand decides how damage scales. An off-hand weapon
            // contributes its stats but never overrides that choice.
            if ($item->equippedSlot() === EquipmentSlot::MainHand && $definition->weaponClass !== null) {
                $scaling = $definition->weaponClass->scalingAttribute();
            }
        }

        return new EquipmentBonuses(
            attributes: $attributes,
            armourValue: $armour,
            weaponBaseDamage: $weaponDamage,
            flatDamage: $flatDamage,
            critChanceBp: $critChance,
            critPowerBp: $critPower,
            dodgeChanceBp: $dodge,
            accuracyBp: $accuracy,
            resistances: $resistances,
            scalingAttribute: $scaling,
        );
    }

    /**
     * An item's affixes, flats before percentages and each group sorted by id.
     *
     * @param array<string, AffixDefinition> $affixes
     *
     * @return list<array{AffixDefinition, int}>
     */
    private static function orderedAffixes(ItemInstance $item, array $affixes): array
    {
        $resolved = [];

        foreach ($item->affixes() as $rolled) {
            $definition = $affixes[$rolled->affixId] ?? null;

            if ($definition !== null) {
                $resolved[] = [$definition, $rolled->value];
            }
        }

        usort($resolved, static function (array $a, array $b): int {
            $modeOrder = ($a[0]->mode === ModifierMode::Flat ? 0 : 1) <=> ($b[0]->mode === ModifierMode::Flat ? 0 : 1);

            return $modeOrder !== 0 ? $modeOrder : strcmp($a[0]->id, $b[0]->id);
        });

        return $resolved;
    }
}
