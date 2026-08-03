<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Renders login outcomes in the API envelope rather than the security
 * component's defaults, so that clients see one response shape everywhere.
 */
final class JsonLoginHandler implements AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        return $this->responder->ok([
            'account' => [
                'id' => $user instanceof AccountUser ? $user->accountId() : null,
                'email' => $user?->getUserIdentifier(),
            ],
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Every failure is audited: credential stuffing is visible in aggregate
        // long before it is visible in any single request.
        $this->logger->warning('Authentication failed', [
            'ip_hash' => hash('sha256', (string) $request->getClientIp()),
            'reason' => $exception::class,
        ]);

        // Deliberately identical for an unknown address and a wrong password,
        // so the response cannot be used to enumerate registered accounts.
        return $this->responder->error(
            ErrorCode::InvalidCredentials,
            'Those credentials are not valid.',
        );
    }
}
