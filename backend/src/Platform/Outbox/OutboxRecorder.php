<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

/**
 * Records a domain event for deferred publication.
 *
 * Used for effects a player would not notice missing for thirty seconds:
 * achievements, leaderboard refreshes, analytics. Effects
 * that must be atomic with the action — experience, gold, loot, inventory —
 * are applied synchronously inside the same transaction instead.
 *
 * See ADR-0004 for the test that decides which path an effect belongs on.
 */
interface OutboxRecorder
{
    /**
     * Stages an event. It is persisted when the surrounding transaction
     * commits, and never on its own.
     *
     * @param array<string, mixed> $payload Ids and primitives only, never
     *                                      entities: an entity in an event is a
     *                                      reference to mutable state that may
     *                                      have changed by the time an
     *                                      asynchronous handler reads it.
     */
    public function record(string $eventType, array $payload): void;
}
