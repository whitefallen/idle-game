<?php

declare(strict_types=1);

namespace App\Feature\Account\Http;

use App\Feature\Account\Application\RegisterAccountHandler;
use App\Feature\Account\Domain\Repository\AccountRepository;
use App\Platform\Http\ApiException;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use App\Platform\Http\JsonBody;
use App\Platform\Security\CurrentAccount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Login and logout are handled by the firewall (see config/packages/security.yaml);
 * this controller covers registration and the current-session lookup.
 *
 * The controller coordinates and does not calculate: it parses the request,
 * calls one handler, and serialises the result.
 */
#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    /**
     * Declared so the router can match the path; the request never reaches this
     * method. Symfony's RouterListener runs before the firewall, so without a
     * route the login path would 404 before the authenticator ever saw it.
     * Authentication itself is performed by the json_login listener.
     */
    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw ApiException::of(
            ErrorCode::InternalError,
            'The authentication listener did not handle this request.',
        );
    }

    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    public function register(
        Request $request,
        RegisterAccountHandler $handler,
        #[Autowire(service: 'limiter.registration')]
        RateLimiterFactoryInterface $registrationLimiter,
    ): JsonResponse {
        // Consumed before any work is done, so a flood costs a Redis-style
        // counter increment rather than a password hash — which is deliberately
        // expensive and would otherwise be the cheapest denial of service
        // available against this endpoint.
        $limit = $registrationLimiter->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw ApiException::of(
                ErrorCode::RateLimited,
                'Too many registrations from this address. Try again later.',
                ['retry_after' => $retryAfter],
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $body = JsonBody::from($request);

        $account = $handler(
            $body->requireString('email'),
            $body->requireString('password'),
        );

        return $this->responder->created([
            'account' => [
                'id' => $account->id()->toRfc4122(),
                'email' => $account->email()->value,
            ],
        ]);
    }

    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(AccountRepository $accounts): JsonResponse
    {
        $account = $accounts->findById($this->currentAccount->id())
            ?? throw ApiException::of(ErrorCode::AuthenticationRequired, 'Authentication is required.');

        return $this->responder->ok([
            'account' => [
                'id' => $account->id()->toRfc4122(),
                'email' => $account->email()->value,
                'created_at' => $account->createdAt()->format(DATE_RFC3339),
            ],
        ]);
    }
}
