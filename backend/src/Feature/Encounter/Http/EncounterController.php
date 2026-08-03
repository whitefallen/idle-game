<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Http;

use App\Feature\Character\Application\CharacterPresenter;
use App\Feature\Encounter\Application\EncounterPresenter;
use App\Feature\Encounter\Application\ResolveEncounterHandler;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use App\Feature\Encounter\Domain\Repository\EncounterRepository;
use App\Feature\Character\Domain\Repository\CharacterRepository;
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
final class EncounterController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
        private readonly EncounterRepository $encounters,
        private readonly EncounterDefinitionRepository $definitions,
        private readonly CharacterRepository $characters,
        private readonly EncounterPresenter $presenter,
        private readonly CharacterPresenter $characterPresenter,
        private readonly IdempotencyStore $idempotency,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * The encounters a character is currently permitted to attempt.
     *
     * Requirements are exposed structurally so the UI can always say what is
     * missing and how far away it is. "You cannot do this yet" without a reason
     * is considered a bug. See docs/progression.md section 5.
     */
    #[Route('/characters/{characterId}/encounters/available', name: 'encounter_available', methods: ['GET'])]
    public function available(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);

        $available = [];

        foreach ($this->definitions->all() as $definition) {
            $available[] = [
                'id' => $definition->id,
                'localisation_key' => $definition->localisationKey,
                'level' => $definition->level,
                'tier' => $definition->tier->value,
                'vigor_cost' => $definition->vigorCost,
                'required_level' => $definition->requiredLevel,
                'monsters' => $definition->monsterIds,
                'unlocked' => $character->level() >= $definition->requiredLevel,
                'affordable' => $character->hasVigor($definition->vigorCost),
            ];
        }

        return $this->responder->ok(['encounters' => $available]);
    }

    #[Route('/encounters', name: 'encounter_resolve', methods: ['POST'])]
    public function resolve(Request $request, ResolveEncounterHandler $handler): JsonResponse
    {
        $accountId = $this->currentAccount->id();
        $body = JsonBody::from($request);

        $idempotencyKey = IdempotencyStore::keyFrom($request);
        $requestHash = IdempotencyStore::hashOf($request);

        // A replayed key returns the original outcome rather than fighting
        // again. Without this a double-tapped button costs Vigor twice.
        if ($idempotencyKey !== null) {
            $replayed = $this->idempotency->replay($idempotencyKey, $accountId, $requestHash);

            if ($replayed !== null) {
                $response = $this->responder->ok($replayed['body'], $replayed['status']);
                $response->headers->set('Idempotency-Replayed', 'true');

                return $response;
            }
        }

        $resolved = $handler(
            $accountId,
            $this->parseId($body->requireString('character_id'), 'Character'),
            $body->requireString('encounter_id'),
        );

        $payload = [
            'encounter' => $this->presenter->detail($resolved->encounter, $resolved->log->toArray()),
            // The post-fight character state travels with the result, so the
            // client never has to re-fetch to show the new experience and gold.
            'character' => $this->characterPresenter->detail($resolved->character),
        ];

        if ($idempotencyKey !== null) {
            $this->idempotency->remember($idempotencyKey, $accountId, $requestHash, $payload, 201);
            $this->transactions->commit();
        }

        return $this->responder->created(
            $payload,
            '/api/v1/encounters/' . $resolved->encounter->id()->toRfc4122(),
        );
    }

    #[Route('/encounters/{id}', name: 'encounter_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $encounter = $this->encounters->findById($this->parseId($id, 'Encounter'));

        if ($encounter === null) {
            throw ApiException::notFound('Encounter');
        }

        // Ownership is checked through the character, since an encounter has no
        // account of its own. Reported as absent rather than forbidden.
        $character = $this->characters->findById($encounter->characterId());

        if ($character === null || !$character->isOwnedBy($this->currentAccount->id())) {
            throw ApiException::notFound('Encounter');
        }

        return $this->responder->ok([
            'encounter' => $this->presenter->detail($encounter, $encounter->log()),
        ]);
    }

    #[Route('/characters/{characterId}/encounters', name: 'encounter_history', methods: ['GET'])]
    public function history(string $characterId): JsonResponse
    {
        $character = $this->ownedCharacter($characterId);

        return $this->responder->ok([
            'encounters' => array_map(
                $this->presenter->summary(...),
                $this->encounters->findRecentForCharacter($character->id(), 20),
            ),
        ]);
    }

    private function ownedCharacter(string $characterId): \App\Feature\Character\Domain\Entity\Character
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
