<?php

declare(strict_types=1);

namespace App\Feature\Quest\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Quest\Application\AcceptQuestHandler;
use App\Feature\Quest\Application\ClaimQuestHandler;
use App\Feature\Quest\Application\QuestPresenter;
use App\Feature\Quest\Domain\Repository\QuestDefinitionRepository;
use App\Feature\Quest\Domain\Repository\QuestRunRepository;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Idempotency\IdempotencyStore;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Security\CurrentAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1')]
final class QuestController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly CharacterRepository $characters,
        private readonly QuestDefinitionRepository $definitions,
        private readonly QuestRunRepository $runs,
        private readonly QuestPresenter $presenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/characters/{characterId}/quests', name: 'quest_list', methods: ['GET'])]
    public function list(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);
        $now = $this->clock->now();

        return $this->responder->ok([
            'quests' => $this->presenter->list(
                $this->definitions->all(),
                $this->runs->findAllForCharacter($character->id()),
                $character,
                $now,
            ),
        ]);
    }

    #[Route('/characters/{characterId}/quests/{questId}/accept', name: 'quest_accept', methods: ['POST'])]
    public function accept(string $characterId, string $questId, Request $request, AcceptQuestHandler $handler): JsonResponse
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

        $run = $handler($accountId, $id, $questId);

        $payload = [
            'quest_id' => $run->questId(),
            'status' => $run->status()->value,
            'completes_at' => $run->completesAt()->format(DATE_RFC3339),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 200);
            $this->transactions->commit();
        }

        return $this->responder->ok($payload);
    }

    #[Route('/characters/{characterId}/quests/{questId}/claim', name: 'quest_claim', methods: ['POST'])]
    public function claim(string $characterId, string $questId, Request $request, ClaimQuestHandler $handler): JsonResponse
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

        $claimed = $handler($accountId, $id, $questId);

        $payload = [
            'quest' => $this->presenter->claimResult($claimed, $claimed->log?->toArray()),
            // The post-claim character travels with the result, so the client
            // never has to re-fetch to show new experience and gold.
            'character' => $this->characterPresenter->detail($claimed->character),
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
