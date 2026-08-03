<?php

declare(strict_types=1);

namespace App\Platform\Random;

/**
 * Produces the seeds that drive combat resolution.
 *
 * Injected rather than called statically so that a test can pin a seed and get
 * a known fight, and so the one place ambient randomness enters the system is
 * explicit. Randomness originates only on the server — the client never
 * supplies, influences, or learns a seed before the fight is resolved.
 */
interface SeedGenerator
{
    /** A full-width 64-bit seed. */
    public function generate(): int;
}
