<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Replay protection for resource-granting endpoints.
 *
 * See docs/api.md section 4.
 */
final class IdempotencyStore
{
    private const string HEADER = 'Idempotency-Key';

    private const int MAX_KEY_LENGTH = 128;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Clock $clock,
    ) {
    }

    public static function keyFrom(Request $request): ?string
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('%s must be at most %d characters.', self::HEADER, self::MAX_KEY_LENGTH),
            );
        }

        return $key;
    }

    public static function hashOf(Request $request): string
    {
        return hash('sha256', $request->getContent());
    }

    /**
     * Returns the original outcome if this key has already been used.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     *
     * @throws ApiException when the key was used with a different request body
     */
    public function replay(string $key, Uuid $accountId, string $requestHash): ?array
    {
        $record = $this->entityManager->find(IdempotencyRecord::class, $key);

        if ($record === null) {
            return null;
        }

        if (!$record->matches($accountId, $requestHash)) {
            throw ApiException::of(
                ErrorCode::IdempotencyConflict,
                'That idempotency key was already used with a different request.',
            );
        }

        return ['status' => $record->status(), 'body' => $record->response()];
    }

    /**
     * Stages the outcome. Persisted with the surrounding transaction, so a
     * rolled-back action leaves no record claiming it succeeded.
     *
     * @param array<string, mixed> $response
     */
    public function remember(
        string $key,
        Uuid $accountId,
        string $requestHash,
        array $response,
        int $status,
    ): void {
        $this->entityManager->persist(new IdempotencyRecord(
            $key,
            $accountId,
            $requestHash,
            $response,
            $status,
            $this->clock->now(),
        ));
    }
}
