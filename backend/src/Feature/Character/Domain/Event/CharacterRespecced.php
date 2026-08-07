<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Emitted when a character's attributes have been reset to base.
 *
 * Dispatched synchronously, inside the respec transaction (ADR-0007): a
 * subscriber's work commits with the reallocation or not at all. This is not an
 * outbox event and carries no NAME or toArray() — it is never serialised, so
 * giving it that shape would advertise a delivery path it does not take.
 *
 * Carries the attributes *after* the reset rather than the character, because a
 * subscriber asking "what may this character still wear" needs the new values
 * and nothing else. Passing the entity would hand every subscriber the ability
 * to mutate another feature's aggregate.
 */
final readonly class CharacterRespecced
{
    /**
     * @param array<string, int> $attributes Allocated attributes after the reset, keyed by Attribute value.
     */
    public function __construct(
        public Uuid $characterId,
        public int $level,
        public array $attributes,
    ) {
    }
}
