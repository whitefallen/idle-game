<?php

declare(strict_types=1);

namespace App\Platform\Http;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An error intended to reach the client.
 *
 * Anything not thrown as one of these is treated as an internal fault and
 * reported as INTERNAL_ERROR with a correlation id, so an unexpected exception
 * can never leak a stack trace, a class name or a SQL fragment to a player.
 *
 * Implements HttpExceptionInterface so the framework recognises it as a
 * deliberate client-facing outcome rather than a fault: a rejected password or
 * a duplicate email is an expected result of an endpoint doing its job, and
 * logging those at critical severity buries the failures that do matter.
 */
final class ApiException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, mixed> $details Structured context the UI can render.
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode->httpStatus(), $previous);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function of(ErrorCode $code, string $message, array $details = []): self
    {
        return new self($code, $message, $details);
    }

    public function getStatusCode(): int
    {
        return $this->errorCode->httpStatus();
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public static function notFound(string $what): self
    {
        // Deliberately identical whether the resource is absent or merely not
        // owned by the caller: a 403 would confirm existence and turn any id
        // endpoint into an enumeration oracle. See docs/api.md section 5.
        return new self(ErrorCode::NotFound, sprintf('%s not found.', $what));
    }
}
