<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Rng;

use InvalidArgumentException;

/**
 * Counter-based randomness for combat resolution.
 *
 * This class holds no state. Every value is derived purely from its
 * coordinates — encounter seed, round, actor, purpose and roll index — so two
 * calls with the same coordinates always return the same value, in any order,
 * on any machine, forever.
 *
 * The alternative, a stateful generator drawn from in sequence, was rejected:
 * with sequential draws, introducing one new roll site shifts every subsequent
 * value, so adding an ability would silently change the outcome of every stored
 * replay and every balance test. Here each site is independent.
 *
 * See docs/combat.md section 3 and ADR-0002.
 */
final class DeterministicRng
{
    /**
     * Rejection sampling draws from a 31-bit window. Keeping the window
     * non-negative avoids any signed/unsigned ambiguity in the comparison.
     */
    private const int SAMPLE_SPAN = 1 << 31;

    private const int MAX_BOUND = self::SAMPLE_SPAN;

    /** Guards against a pathological coordinate set spinning forever. */
    private const int MAX_REJECTIONS = 64;

    private function __construct()
    {
    }

    /**
     * The raw 64-bit value for a coordinate set.
     *
     * Coordinates are folded in one at a time, each mixed before being combined,
     * so that adjacent inputs (round 3 versus round 4) produce entirely
     * unrelated outputs rather than correlated ones.
     */
    public static function value(
        int $seed,
        int $round,
        int $actorOrdinal,
        RollPurpose $purpose,
        int $rollIndex,
        int $attempt = 0,
    ): int {
        // The mix function maps 0 to 0, so an all-zero coordinate set would
        // otherwise yield 0. Offsetting by the golden gamma removes that edge
        // without weakening any other input.
        $state = Int64::add($seed, SplitMix64::GOLDEN_GAMMA);
        $state = SplitMix64::mix($state ^ SplitMix64::mix($round));
        $state = SplitMix64::mix($state ^ SplitMix64::mix($actorOrdinal));
        $state = SplitMix64::mix($state ^ SplitMix64::mix($purpose->value));
        $state = SplitMix64::mix($state ^ SplitMix64::mix($rollIndex));

        return SplitMix64::mix($state ^ SplitMix64::mix($attempt));
    }

    /**
     * A uniform integer in [0, $bound).
     *
     * Uses rejection sampling rather than modulo. Modulo bias is small but it
     * is real, it becomes measurable across millions of drops, and it is
     * embarrassing to have to correct in a live economy. Rejection remains
     * fully deterministic because the retry is itself a coordinate.
     *
     * @param positive-int $bound
     *
     * @return int<0, max>
     */
    public static function below(
        int $seed,
        int $round,
        int $actorOrdinal,
        RollPurpose $purpose,
        int $rollIndex,
        int $bound,
    ): int {
        if ($bound < 1 || $bound > self::MAX_BOUND) {
            throw new InvalidArgumentException(
                sprintf('Roll bound must be within [1, %d], got %d.', self::MAX_BOUND, $bound),
            );
        }

        // The largest multiple of $bound that fits in the sample window.
        // Values at or above it would over-represent the low residues.
        $limit = self::SAMPLE_SPAN - (self::SAMPLE_SPAN % $bound);

        for ($attempt = 0; $attempt < self::MAX_REJECTIONS; ++$attempt) {
            $raw = self::value($seed, $round, $actorOrdinal, $purpose, $rollIndex, $attempt);

            // Take the top 31 bits: the high bits of a splitmix64 output are
            // the best mixed, and 31 bits keeps the value non-negative. The
            // mask is a no-op after a 33-bit shift, but it states the range
            // for the reader and for static analysis.
            $sample = Int64::unsignedShiftRight($raw, 33) & 0x7FFFFFFF;

            if ($sample < $limit) {
                return $sample % $bound;
            }
        }

        throw new \LogicException(
            'Rejection sampling failed to converge; this indicates a defect in the mixing function.',
        );
    }

    /**
     * A probability check against a chance expressed in basis points.
     *
     * 0 bp never succeeds and 10000 bp always succeeds, both without consuming
     * a draw, so certain outcomes stay certain regardless of the mixing.
     *
     * Values outside [0, 10000] are clamped rather than rejected: callers pass
     * derived stats such as dodge minus accuracy, which are legitimately
     * allowed to fall outside the range before clamping.
     */
    public static function chance(
        int $seed,
        int $round,
        int $actorOrdinal,
        RollPurpose $purpose,
        int $rollIndex,
        int $chanceBp,
    ): bool {
        if ($chanceBp <= 0) {
            return false;
        }

        if ($chanceBp >= 10000) {
            return true;
        }

        return self::below($seed, $round, $actorOrdinal, $purpose, $rollIndex, 10000) < $chanceBp;
    }
}
