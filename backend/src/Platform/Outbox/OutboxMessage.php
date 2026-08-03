<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A domain event awaiting publication.
 *
 * Written in the same transaction as the state change it describes, which is
 * what makes it an outbox rather than a queue: the event exists if and only if
 * its state change committed. Publishing directly to a broker inside the
 * handler would be a dual write — a crash between commit and publish either
 * loses the event or emits one for a rolled-back change.
 *
 * See ADR-0004.
 */
#[ORM\Entity]
#[ORM\Table(name: 'outbox')]
#[ORM\Index(
    name: 'idx_outbox_unpublished',
    columns: ['occurred_at'],
    options: ['where' => '(published_at IS NULL)'],
)]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(Uuid $id, string $eventType, array $payload, DateTimeImmutable $occurredAt)
    {
        $this->id = $id;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function markPublished(DateTimeImmutable $now): void
    {
        $this->publishedAt = $now;
    }

    /**
     * Recorded so a message that repeatedly fails to publish is visible rather
     * than silently retried forever.
     */
    public function recordAttempt(): void
    {
        ++$this->attempts;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
