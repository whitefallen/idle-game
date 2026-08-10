<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Dungeon\Application\DungeonPresenter;
use App\Feature\Dungeon\Application\EnterDungeonHandler;
use App\Feature\Dungeon\Application\PickDungeonDisciplineHandler;
use App\Feature\Dungeon\Domain\Repository\DungeonDefinitionRepository;
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
final class DungeonController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly CharacterRepository $characters,
        private readonly DungeonDefinitionRepository $definitions,
        private readonly DungeonPresenter $presenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
    ) {
    }

    #[Route('/characters/{characterId}/dungeons', name: 'dungeon_list', methods: ['GET'])]
    public function list(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);

        return $this->responder->ok([
            'dungeons' => $this->presenter->list($this->definitions->all(), $character),
        ]);
    }

    /**
     * Consumes the dungeon's key and resolves every stage in one request.
     *
     * Idempotency matters more here than almost anywhere else: a retried
     * double-tap on this endpoint without replay protection would consume a
     * second key and fight the whole dungeon twice.
     */
    #[Route('/characters/{characterId}/dungeons/{dungeonId}/enter', name: 'dungeon_enter', methods: ['POST'])]
    public function enter(string $characterId, string $dungeonId, Request $request, EnterDungeonHandler $handler): JsonResponse
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

        $entered = $handler($accountId, $id, $dungeonId);

        $payload = [
            'run' => $this->presenter->detail($entered->run, $entered->run->logs()),
            // The post-run character travels with the result, so the client
            // never has to re-fetch to show new experience and gold.
            'character' => $this->characterPresenter->detail($entered->character),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    /**
     * Confirms a discipline pick from a dungeon clear's offer. A separate
     * step from `enter` on purpose — see PickDungeonDisciplineHandler.
     */
    #[Route(
        '/characters/{characterId}/dungeons/runs/{runId}/discipline',
        name: 'dungeon_pick_discipline',
        methods: ['POST'],
    )]
    public function pickDiscipline(
        string $characterId,
        string $runId,
        Request $request,
        PickDungeonDisciplineHandler $handler,
    ): JsonResponse {
        $accountId = $this->currentAccount->id();
        $id = $this->parseId($characterId, 'Character');
        $runUuid = $this->parseId($runId, 'Dungeon run');
        $disciplineId = JsonBody::from($request)->requireString('discipline_id');

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

        $picked = $handler($accountId, $id, $runUuid, $disciplineId);

        $payload = [
            'run' => $this->presenter->detail($picked->run, $picked->run->logs()),
            'character' => $this->characterPresenter->detail($picked->character),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    private function ownedCharacter(string $characterId): Character
    {
        $character = $this->characters->findById($this->parseId($characterId, 'Character'));

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
