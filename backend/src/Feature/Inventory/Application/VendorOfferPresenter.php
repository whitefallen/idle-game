<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Model\RolledAffix;
use App\Feature\Inventory\Domain\Model\VendorOffer;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;

final class VendorOfferPresenter
{
    public function __construct(
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
    ) {
    }

    /**
     * @param list<VendorOffer> $offers
     *
     * @return list<array<string, mixed>>
     */
    public function collection(array $offers): array
    {
        return array_map($this->one(...), $offers);
    }

    /**
     * @return array<string, mixed>
     */
    public function one(VendorOffer $offer): array
    {
        $definition = $this->definitions->has($offer->definitionId)
            ? $this->definitions->get($offer->definitionId)
            : null;

        return [
            'offer_index' => $offer->index,
            'definition_id' => $offer->definitionId,
            'localisation_key' => $definition === null ? $offer->definitionId : $definition->localisationKey,
            'slot' => $definition?->slot->value,
            'icon' => $definition?->icon,
            'item_level' => $offer->itemLevel,
            'rarity' => $offer->rarity->value,
            'two_handed' => $definition !== null && $definition->twoHanded,
            'weapon_class' => $definition?->weaponClass?->value,
            'base_armour' => $definition?->baseArmour() ?? 0,
            'base_damage' => $definition?->baseWeaponDamage() ?? 0,
            'requirements' => [
                'level' => $definition === null ? 1 : $definition->requiredLevel,
                'attributes' => $definition === null ? [] : $definition->attributeRequirements,
            ],
            'affixes' => $this->affixesOf($offer->affixes),
            'price' => $offer->price,
        ];
    }

    /**
     * @param list<RolledAffix> $rolled
     *
     * @return list<array<string, mixed>>
     */
    private function affixesOf(array $rolled): array
    {
        $described = [];

        foreach ($rolled as $affixRoll) {
            if (!$this->affixes->has($affixRoll->affixId)) {
                continue;
            }

            $affix = $this->affixes->get($affixRoll->affixId);

            $described[] = [
                'id' => $affixRoll->affixId,
                'localisation_key' => $affix->localisationKey,
                'kind' => $affix->kind->value,
                'stat' => $affix->stat->value,
                'mode' => $affix->mode->value,
                'tier' => $affixRoll->tier,
                'value' => $affixRoll->value,
            ];
        }

        return $described;
    }
}
