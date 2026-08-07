<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\ItemRequirements;
use Symfony\Component\Uid\Uuid;

/**
 * Strips equipped items the character no longer qualifies to wear.
 *
 * Exists because respec can lower an allocated attribute below what a worn item
 * demands. EquipItemHandler checks requirements at the moment an item is put
 * on, which is the right place for it, but nothing re-checks what is already
 * worn — so without this a player could allocate into a requirement, equip,
 * respec into a different build and keep the gear. That is a server-authority
 * hole, not a feature: the check has to survive the character changing
 * underneath the item.
 *
 * Nothing is flushed here — the caller owns the transaction, so a respec that
 * rolls back leaves the gear on.
 */
final class UnequipUnmetRequirementsHandler
{
    public function __construct(
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
    ) {
    }

    /**
     * @param array<string, int> $attributes Allocated attributes after the change, keyed by Attribute value.
     *
     * @return list<ItemInstance> What was taken off, so the caller can tell the
     *                            player. Empty is the common case.
     */
    public function __invoke(Uuid $characterId, int $level, array $attributes): array
    {
        $definitions = $this->definitions->all();
        $removed = [];

        foreach ($this->items->findEquippedByCharacter($characterId) as $item) {
            $definition = $definitions[$item->definitionId()] ?? null;

            // An item whose definition has vanished from content cannot be
            // checked. Leaving it on is the safer failure: a content id that
            // disappears is an authoring mistake, and stripping a player's gear
            // over it would turn a build error into player-visible loss.
            if ($definition === null) {
                continue;
            }

            if (ItemRequirements::met($level, $attributes, $definition)) {
                continue;
            }

            $item->unequip();
            $removed[] = $item;
        }

        return $removed;
    }
}
