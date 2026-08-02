<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Rng;

use App\Feature\Combat\Domain\Rng\Int64;
use App\Feature\Combat\Domain\Rng\SplitMix64;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the implementation against the published splitmix64 reference vectors.
 *
 * This matters more than a typical algorithm test: the constants are written as
 * signed decimals because the hex literals exceed PHP_INT_MAX, and a single
 * transcription error would produce a generator that still looks random while
 * silently disagreeing with every other implementation of splitmix64.
 */
#[CoversClass(SplitMix64::class)]
final class SplitMix64Test extends TestCase
{
    private static function hex(int $value): string
    {
        return sprintf('%016X', $value);
    }

    /**
     * The canonical sequence produced by splitmix64 seeded with 0.
     */
    public function testMatchesReferenceVectorsFromZeroSeed(): void
    {
        $expected = [
            'E220A8397B1DCDAF',
            '6E789E6AA1B965F4',
            '06C45D188009454F',
            'F88BB8A8724C81EC',
            '1B39896A51A8749B',
        ];

        $seed = 0;
        $actual = [];

        foreach ($expected as $_) {
            $seed = Int64::add($seed, SplitMix64::GOLDEN_GAMMA);
            $actual[] = self::hex(SplitMix64::mix($seed));
        }

        self::assertSame($expected, $actual);
    }

    public function testNextMatchesTheSameSequence(): void
    {
        self::assertSame('E220A8397B1DCDAF', self::hex(SplitMix64::next(0)));
    }

    /**
     * The golden gamma must equal 0x9E3779B97F4A7C15 as a bit pattern. Written
     * as a decimal in source, so it is worth asserting the pattern directly.
     */
    public function testGoldenGammaBitPattern(): void
    {
        self::assertSame('9E3779B97F4A7C15', self::hex(SplitMix64::GOLDEN_GAMMA));
    }

    /**
     * Documents a real edge the callers must account for: the mix is a
     * finaliser, not a full generator, and it maps zero to zero.
     * {@see \App\Feature\Combat\Domain\Rng\DeterministicRng::value()} offsets by
     * the golden gamma precisely because of this.
     */
    public function testMixMapsZeroToZero(): void
    {
        self::assertSame(0, SplitMix64::mix(0));
    }

    public function testMixIsDeterministic(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertSame(SplitMix64::mix($i), SplitMix64::mix($i));
        }
    }

    /**
     * The mix is a bijection, so distinct inputs must never collide. A collision
     * here would mean two different coordinate sets share a roll value.
     */
    public function testMixIsInjectiveOverASample(): void
    {
        $seen = [];

        for ($i = 0; $i < 20000; ++$i) {
            $seen[SplitMix64::mix($i)] = true;
        }

        self::assertCount(20000, $seen);
    }

    /**
     * Adjacent inputs must produce uncorrelated outputs; roughly half the bits
     * should differ. A weak mix would show a much lower Hamming distance and
     * would make consecutive rounds correlate.
     */
    public function testAdjacentInputsAvalanche(): void
    {
        $totalDifferingBits = 0;
        $samples = 512;

        for ($i = 0; $i < $samples; ++$i) {
            $a = SplitMix64::mix($i);
            $b = SplitMix64::mix($i + 1);

            // %b prints the full unsigned 64-bit pattern, including the sign bit.
            $totalDifferingBits += substr_count(sprintf('%064b', $a ^ $b), '1');
        }

        $averageDifferingBits = $totalDifferingBits / $samples;

        self::assertGreaterThan(26.0, $averageDifferingBits);
        self::assertLessThan(38.0, $averageDifferingBits);
    }
}
