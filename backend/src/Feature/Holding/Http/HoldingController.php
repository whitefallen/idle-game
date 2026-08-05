<?php

declare(strict_types=1);

namespace App\Feature\Holding\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Holding\Application\AssignProductionSlotHandler;
use App\Feature\Holding\Application\ClaimHoldingHandler;
use App\Feature\Holding\Application\HoldingPresenter;
use App\Feature\Holding\Application\HoldingProvisioner;
use App\Platform\Clock\Clock;
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
final class HoldingController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly CharacterRepository $characters,
        private readonly HoldingProvisioner $provisioner,
        private readonly HoldingPresenter $presenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/characters/{characterId}/holding', name: 'holding_get', methods: ['GET'])]
    public function get(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);

        return $this->responder->ok([
            'holding' => $this->presenter->detail(
                // Read-only: a character who has never claimed gets a projection
                // from a transient Holding rather than a row written by a GET.
                $this->provisioner->forRead($character->id()),
                $character->id(),
                $character->level(),
                $this->clock->now(),
            ),
        ]);
    }

    /**
     * Claims everything pending.
     *
     * Takes an idempotency key for the same reason the encounter endpoint does:
     * mobile browsers retry and players double-tap, and without replay
     * protection the second delivery of one intent reads anchors the first
     * already advanced. That would not double-pay — the anchors moved — but it
     * would report an empty claim for an action that actually granted
     * something, which is worse than either outcome on its own.
     */
    #[Route('/characters/{characterId}/holding/claim', name: 'holding_claim', methods: ['POST'])]
    public function claim(string $characterId, Request $request, ClaimHoldingHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();
        $id = $this->parseId($characterId, 'Character');

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

        $claimed = $handler($accountId, $id);
        $now = $this->clock->now();

        $payload = [
            'claimed' => [
                'gold' => $claimed->claimed->gold,
                'materials' => $claimed->claimed->materials,
                'elapsed_seconds' => $claimed->claimed->elapsedSeconds,
            ],
            'holding' => $this->presenter->detail($claimed->holding, $id, $claimed->character->level(), $now),
            // The post-claim character travels with the result, so the client
            // never re-fetches to show its new gold.
            'character' => $this->characterPresenter->detail($claimed->character),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    /**
     * Assigns a production line to a slot. A null material clears it.
     */
    #[Route(
        '/characters/{characterId}/holding/slots/{index}',
        name: 'holding_assign_slot',
        requirements: ['index' => '\d+'],
        methods: ['PUT'],
    )]
    public function assignSlot(
        string $characterId,
        int $index,
        Request $request,
        AssignProductionSlotHandler $handler,
    ): JsonResponse {
        $accountId = $this->currentAccount->id();
        $id = $this->parseId($characterId, 'Character');
        $body = JsonBody::from($request);

        $materialId = $body->optionalString('material_id');

        $holding = $handler($accountId, $id, $index, $materialId);

        $character = $this->characters->findById($id);

        if ($character === null) {
            throw ApiException::notFound('Character');
        }

        return $this->responder->ok([
            'holding' => $this->presenter->detail($holding, $id, $character->level(), $this->clock->now()),
        ]);
    }

    private function ownedCharacter(string $characterId): Character
    {
        $character = $this->characters->findById($this->parseId($characterId, 'Character'));

        // Reported as absent rather than forbidden: a 403 confirms the id
        // exists, which turns the endpoint into an enumeration oracle.
        // See docs/api.md section 5.
        if ($character === null || !$character->isOwnedBy($this->currentAccount->id())) {
            throw ApiException::notFound('Character');
        }

        return $character;
    }

    private function parseId(string $id, string $what): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound($what);
        }

        return Uuid::fromString($id);
    }
}
