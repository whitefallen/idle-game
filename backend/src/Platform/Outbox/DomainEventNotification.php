<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use DateTimeImmutable;

/**
 * The message a relayed domain event travels as.
 *
 * Deliberately generic: subscribers match on the event type rather than on a
 * PHP class, so a feature can subscribe to another feature's event without
 * either one importing the other. The payload is the same ids-and-primitives
 * structure that was persisted, so a message is always reconstructible from
 * its outbox row.
 */
final readonly class DomainEventNotification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventType,
        public array $payload,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
