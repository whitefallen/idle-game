<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Application\EquipItemHandler;
use App\Feature\Inventory\Application\ItemPresenter;
use App\Feature\Inventory\Application\MaterialStackPresenter;
use App\Feature\Inventory\Application\RefineItemHandler;
use App\Feature\Inventory\Application\UnequipItemHandler;
use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use App\Platform\Http\JsonBody;
use App\Platform\Idempotency\IdempotencyStore;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Security\CurrentAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1')]
final class InventoryController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly MaterialStackRepository $materialStacks,
        private readonly ItemPresenter $presenter,
        private readonly MaterialStackPresenter $materialStackPresenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
    ) {
    }

    #[Route('/characters/{characterId}/inventory', name: 'inventory_list', methods: ['GET'])]
    public function list(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);
        $items = $this->items->findByCharacter($character->id());

        $equipped = array_values(array_filter($items, static fn ($item): bool => $item->isEquipped()));
        $carried = array_values(array_filter($items, static fn ($item): bool => !$item->isEquipped()));

        return $this->responder->ok([
            'equipped' => $this->presenter->collection($equipped),
            'carried' => $this->presenter->collection($carried),
            // The refinement material stash, alongside the items it refines —
            // a refine action needs to know what is affordable without a
            // second request to the Holding feature, which owns the stash but
            // is not otherwise involved in equipping or refining anything.
            'materials' => $this->materialStackPresenter->collection(
                $this->materialStacks->findByCharacter($character->id()),
            ),
        ]);
    }

    #[Route('/items/{id}/equip', name: 'item_equip', methods: ['POST'])]
    public function equip(string $id, Request $request, EquipItemHandler $handler): JsonResponse
    {
        $body = JsonBody::from($request);
        $requested = $body->optionalString('slot');

        $slot = $requested === null ? null : (EquipmentSlot::tryFrom($requested)
            ?? throw ApiException::of(ErrorCode::ValidationFailed, sprintf('Unknown slot "%s".', $requested)));

        $item = $handler($this->currentAccount->id(), $this->parseId($id), $slot);

        return $this->respondWithCharacter($item->characterId(), ['item' => $this->presenter->one($item)]);
    }

    #[Route('/items/{id}/unequip', name: 'item_unequip', methods: ['POST'])]
    public function unequip(string $id, UnequipItemHandler $handler): JsonResponse
    {
        $item = $handler($this->currentAccount->id(), $this->parseId($id));

        return $this->respondWithCharacter($item->characterId(), ['item' => $this->presenter->one($item)]);
    }

    /**
     * Advances an item's refinement by one level, spending gold and the
     * caller-chosen material.
     *
     * Takes an idempotency key for the same reason the Holding claim does:
     * refining spends real gold and a real material stack, and a retried
     * request must replay the first outcome rather than risk a second charge.
     */
    #[Route('/items/{id}/refine', name: 'item_refine', methods: ['POST'])]
    public function refine(string $id, Request $request, RefineItemHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();
        $itemId = $this->parseId($id);
        $materialId = JsonBody::from($request)->requireString('material_id');

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

        $item = $handler($accountId, $itemId, $materialId);

        $payload = ['item' => $this->presenter->one($item)];
        $character = $this->characters->findById($item->characterId());

        if ($character !== null) {
            $payload['character'] = $this->characterPresenter->detail($character);
        }

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    /**
     * Equipping changes derived stats, so the updated character travels with
     * the response. Otherwise every equip would need a follow-up request just
     * to refresh the sheet the player is looking at.
     *
     * @param array<string, mixed> $payload
     */
    private function respondWithCharacter(Uuid $characterId, array $payload): JsonResponse
    {
        $character = $this->characters->findById($characterId);

        if ($character !== null) {
            $payload['character'] = $this->characterPresenter->detail($character);
        }

        return $this->responder->ok($payload);
    }

    private function ownedCharacter(string $characterId): \App\Feature\Character\Domain\Entity\Character
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
