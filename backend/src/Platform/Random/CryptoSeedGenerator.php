<?php

declare(strict_types=1);

namespace App\Platform\Random;

/**
 * Seeds drawn from the system CSPRNG.
 *
 * Cryptographic randomness rather than mt_rand: a predictable seed would let a
 * player anticipate a fight's outcome before committing Vigor to it, and would
 * make the arena exploitable by anyone who could observe enough encounters.
 * The cost is irrelevant — one draw per encounter.
 */
final class CryptoSeedGenerator implements SeedGenerator
{
    public function generate(): int
    {
        return random_int(PHP_INT_MIN, PHP_INT_MAX);
    }
}
