<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Uid\Uuid;

/**
 * Converts every exception raised under /api into the error envelope.
 *
 * Internal exceptions are never exposed: no stack traces, no SQL, no class
 * names, in any environment reachable by a player. An unhandled error becomes
 * INTERNAL_ERROR carrying a correlation id, and that id is what appears in the
 * server log so support can join the two. See docs/api.md section 2.
 */
/**
 * Priority must stay below the security component's own ExceptionListener,
 * which runs at 1. That listener is what converts "not authenticated" into the
 * firewall's entry point (a 401) and "not permitted" into the access denied
 * handler (a 403). Running before it — and stopping propagation, as any
 * listener that sets a response effectively does — collapses both into a bare
 * 403 and tells clients to give up instead of to log in.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: -32)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        // The firewall may already have produced a correctly shaped response
        // through the entry point or the access denied handler. Overwriting it
        // would discard the 401/403 distinction it just established.
        if ($event->hasResponse()) {
            return;
        }

        $exception = $event->getThrowable();

        $response = match (true) {
            $exception instanceof ApiException => $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            ),

            $exception instanceof AuthenticationException => $this->responder->error(
                ErrorCode::AuthenticationRequired,
                'Authentication is required.',
            ),

            $exception instanceof AccessDeniedException => $this->responder->error(
                ErrorCode::Forbidden,
                'You do not have permission to do that.',
            ),

            $exception instanceof HttpExceptionInterface => $this->responder->error(
                $this->codeForStatus($exception->getStatusCode()),
                $this->messageForStatus($exception->getStatusCode()),
            ),

            default => $this->internalError($exception),
        };

        $event->setResponse($response);
    }

    private function internalError(\Throwable $exception): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $correlationId = Uuid::v7()->toRfc4122();

        $this->logger->error('Unhandled API exception', [
            'correlation_id' => $correlationId,
            'exception' => $exception,
        ]);

        return $this->responder->error(
            ErrorCode::InternalError,
            'Something went wrong. Quote the correlation id if you contact support.',
            ['correlation_id' => $correlationId],
        );
    }

    private function codeForStatus(int $status): ErrorCode
    {
        return match ($status) {
            400 => ErrorCode::MalformedRequest,
            401 => ErrorCode::AuthenticationRequired,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::NotFound,
            409 => ErrorCode::Conflict,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::RateLimited,
            default => ErrorCode::InternalError,
        };
    }

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You do not have permission to do that.',
            404 => 'Not found.',
            409 => 'That conflicts with the current state.',
            422 => 'The request was understood but could not be processed.',
            429 => 'Too many requests.',
            default => 'Something went wrong.',
        };
    }
}
