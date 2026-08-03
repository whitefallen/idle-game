<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\EquipmentCalculator;
use App\Platform\Http\ApiException;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Removes an item from its slot.
 *
 * Always permitted. Because allocated attributes are stored separately from
 * equipment bonuses, taking an item off can never leave the character in an
 * invalid state — which is exactly why they are stored separately.
 */
final class UnequipItemHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $itemId): ItemInstance
    {
        return $this->transactions->transactional(
            function () use ($accountId, $itemId): ItemInstance {
                $item = $this->items->findById($itemId);

                if ($item === null) {
                    throw ApiException::notFound('Item');
                }

                $character = $this->characters->findById($item->characterId());

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Item');
                }

                // Idempotent: unequipping something already in the inventory is
                // a no-op rather than an error, so a double-tap is harmless.
                $item->unequip();

                // Flushed first, so the power score is recomputed from the
                // equipment as it now stands rather than as it was.
                $this->transactions->commit();

                $this->refreshPowerScore($character);

                return $item;
            },
        );
    }

    /**
     * The advisory ranking column has to follow equipment, or leaderboards and
     * matchmaking rank players by gear they are no longer wearing. See ADR-0006.
     */
    private function refreshPowerScore(\App\Feature\Character\Domain\Entity\Character $character): void
    {
        $character->updatePowerScore(EquipmentCalculator::sum(
            $this->items->findEquippedByCharacter($character->id()),
            $this->definitions->all(),
            $this->affixes->all(),
        ));
    }
}
