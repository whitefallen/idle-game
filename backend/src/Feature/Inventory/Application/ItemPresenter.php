<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\ModifierMode;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;

final class ItemPresenter
{
    public function __construct(
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
    ) {
    }

    /**
     * @param list<ItemInstance> $items
     *
     * @return list<array<string, mixed>>
     */
    public function collection(array $items): array
    {
        return array_map($this->one(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function one(ItemInstance $item): array
    {
        $definition = $this->definitions->has($item->definitionId())
            ? $this->definitions->get($item->definitionId())
            : null;

        return [
            'id' => $item->id()->toRfc4122(),
            'definition_id' => $item->definitionId(),
            'localisation_key' => $definition === null ? $item->definitionId() : $definition->localisationKey,
            'slot' => $definition?->slot->value,
            'icon' => $definition?->icon,
            'item_level' => $item->itemLevel(),
            'rarity' => $item->rarity()->value,
            'two_handed' => $definition !== null && $definition->twoHanded,
            'weapon_class' => $definition?->weaponClass?->value,
            'equipped_slot' => $item->equippedSlot()?->value,

            // Base stats come from the definition and the item level, never
            // from the stored instance. See docs/items.md section 1.
            'base_armour' => $definition?->baseArmour() ?? 0,
            'base_damage' => $definition?->baseWeaponDamage() ?? 0,

            'requirements' => [
                'level' => $definition === null ? 1 : $definition->requiredLevel,
                'attributes' => $definition === null ? [] : $definition->attributeRequirements,
            ],

            'affixes' => $this->affixesOf($item),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function affixesOf(ItemInstance $item): array
    {
        $described = [];

        foreach ($item->affixes() as $rolled) {
            if (!$this->affixes->has($rolled->affixId)) {
                continue;
            }

            $affix = $this->affixes->get($rolled->affixId);

            $described[] = [
                'id' => $rolled->affixId,
                'localisation_key' => $affix->localisationKey,
                'kind' => $affix->kind->value,
                'stat' => $affix->stat->value,
                'mode' => $affix->mode->value,
                'tier' => $rolled->tier,
                'value' => $rolled->value,
            ];
        }

        return $described;
    }

    /**
     * A tooltip comparing an item against what is worn is the actual decision a
     * player is making, so the API exposes the modifier mode rather than making
     * the client infer it from the stat name.
     */
    public static function isPercentage(ModifierMode $mode): bool
    {
        return $mode === ModifierMode::Percent;
    }
}
