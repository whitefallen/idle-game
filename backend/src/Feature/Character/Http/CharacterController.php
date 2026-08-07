<?php

declare(strict_types=1);

namespace App\Feature\Character\Http;

use App\Feature\Character\Application\AllocateAttributePointsHandler;
use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Application\CreateCharacterHandler;
use App\Feature\Character\Application\RespecHandler;
use App\Feature\Character\Application\UpdateBattlePlanHandler;
use App\Feature\Character\Application\UpdateLoadoutHandler;
use App\Feature\Character\Application\ViewCharacterHandler;
use App\Feature\Combat\Domain\Model\PlanIssue;
use App\Feature\Inventory\Application\ItemPresenter;
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

#[Route('/api/v1/characters')]
final class CharacterController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly ViewCharacterHandler $view,
        private readonly CharacterPresenter $presenter,
        private readonly ItemPresenter $itemPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
    ) {
    }

    #[Route('', name: 'character_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $characters = $this->view->forAccount($this->currentAccount->id());

        return $this->responder->ok([
            'characters' => array_map($this->presenter->summary(...), $characters),
        ]);
    }

    #[Route('', name: 'character_create', methods: ['POST'])]
    public function create(Request $request, CreateCharacterHandler $handler): JsonResponse
    {
        $body = JsonBody::from($request);

        $character = $handler($this->currentAccount->id(), $body->requireString('name'));

        return $this->responder->created(
            ['character' => $this->presenter->detail($character)],
            '/api/v1/characters/' . $character->id()->toRfc4122(),
        );
    }

    #[Route('/{id}', name: 'character_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $character = $this->view->one($this->currentAccount->id(), $this->parseId($id));

        return $this->responder->ok(['character' => $this->presenter->detail($character)]);
    }

    #[Route('/{id}/attributes', name: 'character_allocate', methods: ['POST'])]
    public function allocate(
        string $id,
        Request $request,
        AllocateAttributePointsHandler $handler,
    ): JsonResponse {
        $body = JsonBody::from($request);

        $character = $handler(
            $this->currentAccount->id(),
            $this->parseId($id),
            $body->requireObject('allocation'),
        );

        return $this->responder->ok(['character' => $this->presenter->detail($character)]);
    }

    /**
     * Resets every allocated attribute and returns the points, for gold.
     *
     * Takes no body: the cost is derived from the character's level server-side
     * and the reset is total, so there is nothing for the client to say. It
     * still accepts an idempotency key, for the same reason buy and refine do —
     * it spends real gold, and a retried request must not charge twice.
     *
     * The response carries `unequipped` because a respec can take gear off:
     * an item whose attribute requirement the new allocation no longer meets
     * cannot stay on (see UnequipUnmetRequirementsHandler).
     */
    #[Route('/{id}/respec', name: 'character_respec', methods: ['POST'])]
    public function respec(string $id, Request $request, RespecHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();

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

        $outcome = $handler($accountId, $this->parseId($id));

        $payload = [
            'character' => $this->presenter->detail($outcome->character),
            'gold_spent' => $outcome->goldSpent,
            'unequipped' => array_map($this->itemPresenter->one(...), $outcome->unequipped),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    /**
     * Replaces the whole plan rather than patching rules individually.
     *
     * A plan is an ordered list evaluated top to bottom, so its meaning depends
     * on the order as much as the contents. Patching one rule at a time would
     * make two concurrent edits produce a plan neither player wrote.
     */
    #[Route('/{id}/battle-plan', name: 'character_update_battle_plan', methods: ['PUT'])]
    public function updateBattlePlan(
        string $id,
        Request $request,
        UpdateBattlePlanHandler $handler,
    ): JsonResponse {
        $body = JsonBody::from($request);

        /** @var list<array<string, mixed>> $rules */
        $rules = $body->requireList('rules');

        $update = $handler($this->currentAccount->id(), $this->parseId($id), $rules);

        return $this->responder->ok([
            'character' => $this->presenter->detail($update->character),
            // Non-blocking problems travel with the successful save so the
            // editor can flag them without having refused the plan.
            'warnings' => array_map(static fn (PlanIssue $i): array => $i->toArray(), $update->warnings),
        ]);
    }

    /**
     * Replaces the slotted loadout wholesale, for the same reason the battle
     * plan is replaced wholesale: which abilities are slotted *together* is the
     * decision, and patching one slot at a time would let two concurrent edits
     * produce a loadout neither player chose.
     *
     * The client sends ability ids. It does not send discipline ids, its own
     * level, or the set it believes it has unlocked — the server derives all of
     * that.
     */
    #[Route('/{id}/loadout', name: 'character_update_loadout', methods: ['PUT'])]
    public function updateLoadout(
        string $id,
        Request $request,
        UpdateLoadoutHandler $handler,
    ): JsonResponse {
        $body = JsonBody::from($request);

        /** @var list<string> $abilityIds */
        $abilityIds = array_values(array_map(strval(...), $body->requireList('ability_ids')));

        $character = $handler($this->currentAccount->id(), $this->parseId($id), $abilityIds);

        return $this->responder->ok(['character' => $this->presenter->detail($character)]);
    }

    /**
     * A malformed id is reported as absent rather than as a validation error,
     * so a caller cannot distinguish "no such character" from "not yours".
     */
    private function parseId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound('Character');
        }

        return Uuid::fromString($id);
    }
}
