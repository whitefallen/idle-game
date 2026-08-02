<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Rng;

/**
 * Wrapping 64-bit integer arithmetic.
 *
 * PHP's native `+` and `*` silently promote to float on overflow, which would
 * violate determinism rule R1 in docs/combat.md: float results are not reliably
 * identical across platforms and builds. These helpers perform the same
 * operations with explicit wrap-around, decomposing the operands into smaller
 * lanes so no intermediate product can exceed PHP_INT_MAX.
 *
 * Values are treated as raw 64-bit patterns. PHP integers are signed, so a
 * result with the top bit set reads as negative; that is the correct two's
 * complement representation and callers must not interpret it as a magnitude.
 */
final class Int64
{
    private const int MASK_16 = 0xFFFF;
    private const int MASK_32 = 0xFFFFFFFF;

    private function __construct()
    {
    }

    /**
     * Addition modulo 2^64.
     *
     * Operands are split into 32-bit halves. Each half-sum reaches at most
     * 2^33, well inside PHP_INT_MAX, so no intermediate overflows.
     */
    public static function add(int $a, int $b): int
    {
        $low = ($a & self::MASK_32) + ($b & self::MASK_32);
        $high = (($a >> 32) & self::MASK_32)
            + (($b >> 32) & self::MASK_32)
            + (($low >> 32) & self::MASK_32);

        return (($high & self::MASK_32) << 32) | ($low & self::MASK_32);
    }

    /**
     * Multiplication modulo 2^64.
     *
     * Split into 16-bit lanes rather than 32-bit: a 32x32 product reaches 2^64
     * and would overflow, whereas a 16x16 product reaches only 2^32. Lanes
     * above the fourth are discarded, which is exactly the modulo 2^64 we want.
     */
    public static function mul(int $a, int $b): int
    {
        $a0 = $a & self::MASK_16;
        $a1 = ($a >> 16) & self::MASK_16;
        $a2 = ($a >> 32) & self::MASK_16;
        $a3 = ($a >> 48) & self::MASK_16;

        $b0 = $b & self::MASK_16;
        $b1 = ($b >> 16) & self::MASK_16;
        $b2 = ($b >> 32) & self::MASK_16;
        $b3 = ($b >> 48) & self::MASK_16;

        $c0 = $a0 * $b0;
        $c1 = $a0 * $b1 + $a1 * $b0;
        $c2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0;
        $c3 = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0;

        $r0 = $c0 & self::MASK_16;
        $carry = $c0 >> 16;

        $t1 = $c1 + $carry;
        $r1 = $t1 & self::MASK_16;
        $carry = $t1 >> 16;

        $t2 = $c2 + $carry;
        $r2 = $t2 & self::MASK_16;
        $carry = $t2 >> 16;

        $r3 = ($c3 + $carry) & self::MASK_16;

        return ($r3 << 48) | ($r2 << 32) | ($r1 << 16) | $r0;
    }

    /**
     * Logical (zero-filling) right shift.
     *
     * PHP's `>>` is arithmetic and sign-extends, which corrupts the mixing
     * steps of splitmix64. The mask is derived from PHP_INT_MAX rather than
     * written as `(1 << (64 - $n)) - 1`, because that expression overflows to
     * float when $n is 1.
     *
     * @param int<0, 63> $bits
     */
    public static function unsignedShiftRight(int $value, int $bits): int
    {
        if ($bits === 0) {
            return $value;
        }

        return ($value >> $bits) & (PHP_INT_MAX >> ($bits - 1));
    }
}
