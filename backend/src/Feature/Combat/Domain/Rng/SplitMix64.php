<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Rng;

/**
 * The splitmix64 finalising mix.
 *
 * A bijective avalanche function over 64 bits: every input bit affects roughly
 * half the output bits, and distinct inputs always produce distinct outputs.
 * Both properties matter for the counter-based scheme in {@see DeterministicRng},
 * where nearby coordinates (round 3 vs round 4) must produce unrelated values.
 *
 * The constants exceed PHP_INT_MAX, so they are written as their signed two's
 * complement equivalents; a hex literal that large would be parsed as a float.
 * SplitMix64Test pins them against published reference vectors.
 */
final class SplitMix64
{
    /** 0x9E3779B97F4A7C15 — the golden-ratio increment. */
    public const int GOLDEN_GAMMA = -7046029254386353131;

    /** 0xBF58476D1CE4E5B9 */
    private const int MIX_A = -4658895280553007687;

    /** 0x94D049BB133111EB */
    private const int MIX_B = -7723592293110705685;

    private function __construct()
    {
    }

    /**
     * Mix a 64-bit value. Deterministic, stateless and total.
     */
    public static function mix(int $value): int
    {
        $z = $value;

        $z = Int64::mul($z ^ Int64::unsignedShiftRight($z, 30), self::MIX_A);
        $z = Int64::mul($z ^ Int64::unsignedShiftRight($z, 27), self::MIX_B);

        return $z ^ Int64::unsignedShiftRight($z, 31);
    }

    /**
     * Advance a seed by the golden-ratio increment, then mix.
     *
     * This is the classic sequential splitmix64 step. It is used to derive
     * encounter seeds from a root seed, never to draw combat values — combat
     * randomness is counter-based. See docs/combat.md section 3.1.
     */
    public static function next(int $seed): int
    {
        return self::mix(Int64::add($seed, self::GOLDEN_GAMMA));
    }
}
