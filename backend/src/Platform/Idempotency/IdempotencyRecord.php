<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The stored outcome of a mutating request, keyed by the client's idempotency key.
 *
 * Not optional polish: mobile browsers retry, players double-tap, and networks
 * drop responses after the server has already committed. Without this, each of
 * those is a duplicate award or a lost one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idempotency_record')]
#[ORM\Index(name: 'idx_idempotency_created_at', columns: ['created_at'])]
class IdempotencyRecord
{
    /** The client-supplied key is itself the primary key. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;

    /**
     * A hash of the request body. Replaying a key with a different body is a
     * client bug, and returning the first response for it would silently give
     * the caller an answer to a question it did not ask.
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $requestHash;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $response;

    #[ORM\Column(type: 'integer')]
    private int $status;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string $idempotencyKey,
        Uuid $accountId,
        string $requestHash,
        array $response,
        int $status,
        DateTimeImmutable $createdAt,
    ) {
        $this->idempotencyKey = $idempotencyKey;
        $this->accountId = $accountId;
        $this->requestHash = $requestHash;
        $this->response = $response;
        $this->status = $status;
        $this->createdAt = $createdAt;
    }

    public function matches(Uuid $accountId, string $requestHash): bool
    {
        return $this->accountId->equals($accountId) && hash_equals($this->requestHash, $requestHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->status;
    }
}
