<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Combat\Domain\Rng\Int64;
use App\Feature\Combat\Domain\Rng\SplitMix64;

/**
 * Counter-based randomness for loot.
 *
 * Deliberately separate from the combat RNG. Combat roll purposes are pinned to
 * the combat ruleset version, and loot must not be coupled to that: rebalancing
 * combat should not change which items dropped, and rebalancing drops should
 * not change any stored fight.
 *
 * Like combat, each roll is derived from its coordinates rather than drawn from
 * a stream, so adding a new roll site cannot disturb existing ones — and a drop
 * stays reproducible from the encounter seed that produced it.
 */
final class ItemRoll
{
    /** Distinct streams, so two roll sites cannot accidentally correlate. */
    public const int STREAM_RARITY = 0x9A21;
    public const int STREAM_POOL = 0x9A22;
    public const int STREAM_AFFIX_PICK = 0x9A23;
    public const int STREAM_AFFIX_VALUE = 0x9A24;
    public const int STREAM_TABLE_ENTRY = 0x9A25;
    public const int STREAM_ITEM_LEVEL = 0x9A26;
    public const int STREAM_QUANTITY = 0x9A27;

    private const int SAMPLE_BITS = 31;

    private function __construct()
    {
    }

    /**
     * A uniform value in [0, $bound).
     *
     * @param int<1, max> $bound
     *
     * @return int<0, max>
     */
    public static function below(int $seed, int $stream, int $index, int $bound): int
    {
        if ($bound < 1) {
            throw new \InvalidArgumentException('Roll bound must be at least 1.');
        }

        $span = 1 << self::SAMPLE_BITS;
        $limit = $span - ($span % $bound);

        for ($attempt = 0; $attempt < 64; ++$attempt) {
            $mixed = SplitMix64::mix(
                Int64::add($seed, self::coordinate($stream, $index, $attempt)),
            );

            // Rejection sampling rather than modulo. The bias is small but it
            // is real, and it becomes measurable across millions of drops.
            $sample = Int64::unsignedShiftRight($mixed, 64 - self::SAMPLE_BITS) & 0x7FFFFFFF;

            if ($sample < $limit) {
                return $sample % $bound;
            }
        }

        throw new \LogicException('Loot rejection sampling failed to converge.');
    }

    /**
     * A uniform value in [$min, $max], inclusive.
     */
    public static function between(int $seed, int $stream, int $index, int $min, int $max): int
    {
        $span = $max - $min + 1;

        if ($span < 1) {
            throw new \InvalidArgumentException('Inverted roll range.');
        }

        return $min + self::below($seed, $stream, $index, $span);
    }

    /**
     * Picks an index from a weighted list.
     *
     * @param list<int> $weights
     */
    public static function weighted(int $seed, int $stream, int $index, array $weights): int
    {
        $total = array_sum($weights);

        if ($total < 1) {
            throw new \InvalidArgumentException('Weighted roll needs at least one positive weight.');
        }

        $roll = self::below($seed, $stream, $index, $total);
        $running = 0;

        foreach ($weights as $position => $weight) {
            $running += $weight;

            if ($roll < $running) {
                return $position;
            }
        }

        return count($weights) - 1;
    }

    private static function coordinate(int $stream, int $index, int $attempt): int
    {
        return SplitMix64::mix($stream)
            ^ SplitMix64::mix($index)
            ^ SplitMix64::mix($attempt);
    }
}
