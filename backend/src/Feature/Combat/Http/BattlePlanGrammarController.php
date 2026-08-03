<?php

declare(strict_types=1);

namespace App\Feature\Combat\Http;

use App\Feature\Combat\Application\BattlePlanGrammar;
use App\Platform\Http\ApiResponder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes the battle plan grammar.
 *
 * Static for a given release, so the response is cacheable. The editor fetches
 * it once rather than embedding its own copy of the rules — the server decides
 * what a valid plan is, and a client that guessed would offer plans the server
 * then rejects.
 */
#[Route('/api/v1/battle-plan')]
final class BattlePlanGrammarController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly BattlePlanGrammar $grammar,
    ) {
    }

    #[Route('/grammar', name: 'battle_plan_grammar', methods: ['GET'])]
    public function grammar(): JsonResponse
    {
        $response = $this->responder->ok(['grammar' => $this->grammar->describe()]);

        // Changes only with a release, and the editor needs it on every visit.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
