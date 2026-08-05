<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Event\ItemPurchased;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Uid\IdentifierGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * Buys one offer from a character's daily vendor stock.
 *
 * The offer is named by index only. The price, item level, rarity and affixes
 * are never taken from the client — this handler re-derives today's stock
 * through VendorStockResolver, the same deterministic roll the read endpoint
 * used to display it, and mints exactly that. See docs/items.md section 5.
 */
final class BuyVendorItemHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly VendorStockResolver $stock,
        private readonly IdentifierGenerator $identifiers,
        private readonly AuditLogger $audit,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, int $offerIndex): ItemInstance
    {
        // Deliberately before the transaction opens. Freezing inside it would
        // roll the day's snapshot back with a rejected buy, and a client that
        // never calls the read endpoint could then re-roll stock by failing a
        // purchase, changing gear and trying again — the exact loop the
        // snapshot exists to close. Idempotent, so the common path (a read
        // already froze today) costs one indexed lookup. Ownership is checked
        // here as well as under the lock, so a request for someone else's
        // character writes nothing before it is rejected.
        $unlocked = $this->characters->findById($characterId);

        if ($unlocked !== null && $unlocked->isOwnedBy($accountId)) {
            $this->stock->ensureFrozen($unlocked);
        }

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $offerIndex): ItemInstance {
                // Locked first, and always first — the same lock order every
                // other writer in this system uses (RefineItemHandler,
                // HoldingClaimHandler), so requests racing on the same
                // character queue rather than deadlock.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $offers = $this->stock->forCharacter($character);

                if (!isset($offers[$offerIndex])) {
                    throw ApiException::of(
                        ErrorCode::ValidationFailed,
                        'That vendor offer is no longer available.',
                        ['offer_index' => $offerIndex],
                    );
                }

                $offer = $offers[$offerIndex];

                if ($character->gold() < $offer->price) {
                    throw ApiException::of(
                        ErrorCode::InsufficientGold,
                        sprintf('This item costs %d gold.', $offer->price),
                        ['required' => $offer->price, 'available' => $character->gold()],
                    );
                }

                $now = $this->clock->now();

                $item = new ItemInstance(
                    $this->identifiers->generate(),
                    $character->id(),
                    $offer->definitionId,
                    $offer->itemLevel,
                    $offer->rarity,
                    $offer->affixes,
                    $now,
                );

                $character->spendGold($offer->price, $now);
                $this->items->save($item);

                $this->outbox->record(ItemPurchased::NAME, (new ItemPurchased(
                    itemId: $item->id()->toRfc4122(),
                    characterId: $character->id()->toRfc4122(),
                    definitionId: $offer->definitionId,
                    itemLevel: $offer->itemLevel,
                    rarity: $offer->rarity->value,
                    goldSpent: $offer->price,
                ))->toArray());

                $this->audit->record(
                    AuditAction::ItemPurchased,
                    [
                        'itemId' => $item->id()->toRfc4122(),
                        'definitionId' => $offer->definitionId,
                        'offerIndex' => $offerIndex,
                        'gold' => ['spent' => $offer->price, 'balance' => $character->gold()],
                    ],
                    $accountId,
                    $character->id(),
                );

                return $item;
            },
        );
    }
}
