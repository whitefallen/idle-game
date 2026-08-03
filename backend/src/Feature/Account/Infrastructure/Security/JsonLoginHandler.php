<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Http\ApiResponder;
use App\Platform\Http\ErrorCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Renders login outcomes in the API envelope rather than the security
 * component's defaults, so that clients see one response shape everywhere.
 */
final class JsonLoginHandler implements AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly AuditLogger $audit,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        $accountId = $user instanceof AccountUser ? $user->accountId() : null;

        $this->audit->recordNow(
            AuditAction::AuthenticationSucceeded,
            [],
            $accountId === null ? null : Uuid::fromString($accountId),
        );

        return $this->responder->ok([
            'account' => [
                'id' => $accountId,
                'email' => $user?->getUserIdentifier(),
            ],
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Throttling is reported distinctly from bad credentials. The client
        // needs to know to back off rather than to re-prompt for a password,
        // and repeated throttling is itself the signal worth alerting on.
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->audit->recordNow(AuditAction::RateLimitExceeded, ['endpoint' => 'auth.login']);

            return $this->responder->error(
                ErrorCode::RateLimited,
                'Too many attempts. Try again shortly.',
            );
        }

        // Written immediately rather than staged: there is no transaction to
        // join, and this record must survive the request failing. Credential
        // stuffing is visible in aggregate long before it is visible in any
        // single request, so the aggregate has to exist.
        //
        // The account is deliberately not resolved — doing so would mean
        // confirming which addresses are registered in order to write a log line.
        $this->audit->recordNow(AuditAction::AuthenticationFailed, [
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
