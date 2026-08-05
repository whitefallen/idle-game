<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Domain\Event\ItemSold;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Sells an owned, unequipped item to the Vendor for its vendorValue.
 *
 * Equipped items are rejected rather than silently unequipped first: selling
 * gear a player is wearing is very likely a mistake, and unequipping as a side
 * effect of a sale would also have to recompute the advisory power score
 * (ADR-0006) for a request that was not really about equipment at all.
 */
final class SellItemHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AuditLogger $audit,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @return array{definitionId: string, goldAwarded: int, character: Character}
     */
    public function __invoke(Uuid $accountId, Uuid $itemId): array
    {
        return $this->transactions->transactional(
            function () use ($accountId, $itemId): array {
                $item = $this->items->findById($itemId);

                if ($item === null) {
                    throw ApiException::notFound('Item');
                }

                // Locked first, and always first — see BuyVendorItemHandler.
                $character = $this->characters->findByIdForUpdate($item->characterId());

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Item');
                }

                if ($item->isEquipped()) {
                    throw ApiException::of(
                        ErrorCode::ValidationFailed,
                        'Unequip this item before selling it.',
                        ['item_id' => $item->id()->toRfc4122()],
                    );
                }

                $definitionId = $item->definitionId();
                $vendorValue = $this->definitions->has($definitionId)
                    ? $this->definitions->get($definitionId)->vendorValue
                    : 0;

                $now = $this->clock->now();
                $character->awardGold($vendorValue, $now);
                $this->items->remove($item);

                $this->outbox->record(ItemSold::NAME, (new ItemSold(
                    itemId: $item->id()->toRfc4122(),
                    characterId: $character->id()->toRfc4122(),
                    definitionId: $definitionId,
                    goldAwarded: $vendorValue,
                ))->toArray());

                $this->audit->record(
                    AuditAction::ItemSold,
                    [
                        'itemId' => $item->id()->toRfc4122(),
                        'definitionId' => $definitionId,
                        'gold' => ['awarded' => $vendorValue, 'balance' => $character->gold()],
                    ],
                    $accountId,
                    $character->id(),
                );

                return ['definitionId' => $definitionId, 'goldAwarded' => $vendorValue, 'character' => $character];
            },
        );
    }
}
