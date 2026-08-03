<?php

declare(strict_types=1);

namespace App\Platform\Clock;

use DateTimeImmutable;

/**
 * The server's clock.
 *
 * Injected rather than read statically so that time-dependent rules — Vigor
 * regeneration, Holding accrual, cooldowns — are testable without waiting, and
 * so that no code path can accidentally read a client-supplied timestamp.
 *
 * The server owns the clock. See docs/idle.md rule T1.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
