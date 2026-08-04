<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Model;

/**
 * One production slot of a Holding.
 *
 * A slot with no material is idle: it produces nothing and banks nothing, so
 * leaving a slot empty is a cost rather than a delayed reward.
 *
 * Each slot carries **its own accrual anchor**, which docs/data-model.md's
 * original single `last_claimed_at` column could not express. Two reasons, both
 * load-bearing:
 *
 * 1. Lines produce at different rates, so they finish whole units at different
 *    moments. One shared anchor would have to advance by the slowest line's
 *    progress and would silently over- or under-pay every other line.
 * 2. Reassigning a slot resets *that* slot's accrual (docs/idle.md section 2).
 *    With a shared anchor, reassigning one slot would reset all of them.
 *
 * The timestamp is a Unix integer rather than a date object because it is
 * persisted inside a JSON column, where a formatted date invites a timezone or
 * precision difference between what was written and what is read back.
 */
final readonly class ProductionSlot
{
    public function __construct(
        public int $index,
        /** Null means idle: the slot is unlocked but nothing is assigned. */
        public ?string $materialId,
        /** Unix timestamp, server-set. Never client-supplied. */
        public int $accruedAt,
    ) {
    }

    public function isAssigned(): bool
    {
        return $this->materialId !== null;
    }
}
