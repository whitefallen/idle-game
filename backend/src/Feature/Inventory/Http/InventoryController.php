<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Application\EquipItemHandler;
use App\Feature\Inventory\Application\ItemPresenter;
use App\Feature\Inventory\Application\UnequipItemHandler;
use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use App\Platform\Http\JsonBody;
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
        private readonly ItemPresenter $presenter,
        private readonly CharacterPresenter $characterPresenter,
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
