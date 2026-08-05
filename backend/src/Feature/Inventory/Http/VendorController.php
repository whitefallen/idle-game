<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Application\BuyVendorItemHandler;
use App\Feature\Inventory\Application\ItemPresenter;
use App\Feature\Inventory\Application\SellItemHandler;
use App\Feature\Inventory\Application\VendorOfferPresenter;
use App\Feature\Inventory\Application\VendorStockResolver;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\JsonBody;
use App\Platform\Idempotency\IdempotencyStore;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Security\CurrentAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1')]
final class VendorController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly CharacterRepository $characters,
        private readonly VendorStockResolver $stock,
        private readonly VendorOfferPresenter $offerPresenter,
        private readonly ItemPresenter $itemPresenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * Today's stock, rolled deterministically from the character and the
     * date — nothing here is stored, so this is a pure read. See
     * docs/items.md section 5.
     */
    #[Route('/characters/{characterId}/vendor', name: 'vendor_stock', methods: ['GET'])]
    public function stock(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);

        return $this->responder->ok([
            'offers' => $this->offerPresenter->collection($this->stock->forCharacter($character)),
        ]);
    }

    /**
     * Buys one offer by index from today's stock.
     *
     * Takes an idempotency key for the same reason refine and Holding claim
     * do: this spends real gold and grants a real item.
     */
    #[Route('/characters/{characterId}/vendor/buy', name: 'vendor_buy', methods: ['POST'])]
    public function buy(string $characterId, Request $request, BuyVendorItemHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();
        $character = $this->ownedCharacter($characterId);
        $offerIndex = JsonBody::from($request)->requireInt('offer_index');

        $idempotencyKey = IdempotencyStore::keyFrom($request);
        $requestHash = IdempotencyStore::hashOf($request);

        if ($idempotencyKey !== null) {
            $replayed = $this->idempotency->replay($idempotencyKey, $accountId, $requestHash);

            if ($replayed !== null) {
                $response = $this->responder->ok($replayed['body'], $replayed['status']);
                $response->headers->set('Idempotency-Replayed', 'true');

                return $response;
            }
        }

        $item = $handler($accountId, $character->id(), $offerIndex);

        $payload = ['item' => $this->itemPresenter->one($item)];
        $updated = $this->characters->findById($item->characterId());

        if ($updated !== null) {
            $payload['character'] = $this->characterPresenter->detail($updated);
        }

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    /**
     * Sells an owned, unequipped item for its vendorValue.
     *
     * Takes an idempotency key for the same reason buy does.
     */
    #[Route('/items/{id}/sell', name: 'item_sell', methods: ['POST'])]
    public function sell(string $id, Request $request, SellItemHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();
        $itemId = $this->parseId($id);

        $idempotencyKey = IdempotencyStore::keyFrom($request);
        $requestHash = IdempotencyStore::hashOf($request);

        if ($idempotencyKey !== null) {
            $replayed = $this->idempotency->replay($idempotencyKey, $accountId, $requestHash);

            if ($replayed !== null) {
                $response = $this->responder->ok($replayed['body'], $replayed['status']);
                $response->headers->set('Idempotency-Replayed', 'true');

                return $response;
            }
        }

        $result = $handler($accountId, $itemId);

        $payload = [
            'sold' => ['definition_id' => $result['definitionId'], 'gold_awarded' => $result['goldAwarded']],
            'character' => $this->characterPresenter->detail($result['character']),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    private function ownedCharacter(string $characterId): Character
    {
        $character = $this->characters->findById($this->parseId($characterId));

        if ($character === null || !$character->isOwnedBy($this->currentAccount->id())) {
            throw ApiException::notFound('Character');
        }

        return $character;
    }

    private function parseId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound('Item');
        }

        return Uuid::fromString($id);
    }
}
