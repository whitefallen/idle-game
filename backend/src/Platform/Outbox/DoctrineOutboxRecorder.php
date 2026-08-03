<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineOutboxRecorder implements OutboxRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    public function record(string $eventType, array $payload): void
    {
        // persist() only, never flush: the message must commit with the state
        // change that produced it, and the caller owns that boundary.
        $this->entityManager->persist(new OutboxMessage(
            $this->identifiers->generate(),
            $eventType,
            $payload,
            $this->clock->now(),
        ));
    }
}
