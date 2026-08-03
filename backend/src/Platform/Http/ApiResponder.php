<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Clock\Clock;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds the response envelope described in docs/api.md section 2.
 *
 * Every response carries meta.server_time, which is what lets the client
 * interpolate accruing values (Vigor, Holding output) for display without
 * polling and without ever trusting its own clock.
 */
final class ApiResponder
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function ok(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            [
                'data' => $data,
                'meta' => ['server_time' => $this->clock->now()->format(DATE_RFC3339)],
            ],
            $status,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function created(array $data, ?string $location = null): JsonResponse
    {
        $response = $this->ok($data, 201);

        if ($location !== null) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }

    public function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function error(ErrorCode $code, string $message, array $details = []): ApiErrorResponse
    {
        $payload = [
            'code' => $code->value,
            'message' => $message,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return new ApiErrorResponse(['error' => $payload], $code->httpStatus());
    }
}
