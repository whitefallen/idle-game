<?php

declare(strict_types=1);

namespace App\Feature\Character\Http;

use App\Feature\Character\Application\AllocateAttributePointsHandler;
use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Application\CreateCharacterHandler;
use App\Feature\Character\Application\UpdateBattlePlanHandler;
use App\Feature\Character\Application\ViewCharacterHandler;
use App\Feature\Combat\Domain\Model\PlanIssue;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\JsonBody;
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
