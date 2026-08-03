<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * An error response that can carry additional headers fluently.
 *
 * Exists so an error can be built in one expression while still attaching the
 * headers its status requires — Retry-After on a 429 is not optional
 * decoration, it is what tells a client when to come back.
 */
final class ApiErrorResponse extends JsonResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers->set($name, $value);
        }

        return $this;
    }
}
