<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Rng;

use App\Feature\Combat\Domain\Rng\DeterministicRng;
use App\Feature\Combat\Domain\Rng\RollPurpose;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeterministicRng::class)]
final class DeterministicRngTest extends TestCase
{
    private const int SEED = 0x5EED1234ABCD;

    public function testSameCoordinatesAlwaysProduceTheSameValue(): void
    {
        for ($round = 1; $round <= 20; ++$round) {
            $first = DeterministicRng::value(self::SEED, $round, 0, RollPurpose::HitCheck, 0);
            $second = DeterministicRng::value(self::SEED, $round, 0, RollPurpose::HitCheck, 0);

            self::assertSame($first, $second);
        }
    }

    /**
     * The property the whole counter-based design exists for: values at one
     * roll site are unaffected by the existence of any other roll site.
     *
     * With a sequential generator, introducing a new draw would shift every
     * later value, silently changing every stored replay. Here, reading a
     * different purpose in between changes nothing.
     */
    public function testRollSitesAreIndependent(): void
    {
        $baseline = [];
        for ($round = 1; $round <= 50; ++$round) {
            $baseline[$round] = DeterministicRng::below(self::SEED, $round, 0, RollPurpose::HitCheck, 0, 10000);
        }

        // Simulate a later feature adding extra draws at unrelated sites.
        foreach (RollPurpose::cases() as $purpose) {
            if ($purpose === RollPurpose::HitCheck) {
                continue;
            }

            for ($round = 1; $round <= 50; ++$round) {
                DeterministicRng::below(self::SEED, $round, 0, $purpose, 0, 10000);
                DeterministicRng::below(self::SEED, $round, 1, $purpose, 7, 20);
            }
        }

        foreach ($baseline as $round => $expected) {
            self::assertSame(
                $expected,
                DeterministicRng::below(self::SEED, $round, 0, RollPurpose::HitCheck, 0, 10000),
                'Unrelated roll sites must not disturb this one.',
            );
        }
    }

    public function testDistinctCoordinatesProduceDistinctStreams(): void
    {
        $byRound = [];
        $byActor = [];
        $byPurpose = [];

        for ($i = 0; $i < 200; ++$i) {
            $byRound[] = DeterministicRng::value(self::SEED, $i, 0, RollPurpose::HitCheck, 0);
            $byActor[] = DeterministicRng::value(self::SEED, 0, $i, RollPurpose::HitCheck, 0);
            $byPurpose[] = DeterministicRng::value(self::SEED, 0, 0, RollPurpose::HitCheck, $i);
        }

        self::assertCount(200, array_unique($byRound));
        self::assertCount(200, array_unique($byActor));
        self::assertCount(200, array_unique($byPurpose));

        self::assertNotSame($byRound, $byActor);
    }

    public function testAllZeroCoordinatesDoNotCollapseToZero(): void
    {
        // The mix maps 0 to 0; the golden-gamma offset in value() prevents a
        // degenerate all-zero coordinate set from producing a zero stream.
        self::assertNotSame(0, DeterministicRng::value(0, 0, 0, RollPurpose::HitCheck, 0, 0));
    }

    public function testBelowStaysWithinBounds(): void
    {
        foreach ([1, 2, 3, 6, 20, 100, 10000] as $bound) {
            for ($i = 0; $i < 500; ++$i) {
                $value = DeterministicRng::below(self::SEED, $i, 0, RollPurpose::TargetSelection, 0, $bound);

                self::assertGreaterThanOrEqual(0, $value);
                self::assertLessThan($bound, $value);
            }
        }
    }

    public function testBoundOfOneAlwaysReturnsZero(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertSame(0, DeterministicRng::below(self::SEED, $i, 0, RollPurpose::TargetSelection, 0, 1));
        }
    }

    public function testBelowRejectsOutOfRangeBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DeterministicRng::below(self::SEED, 1, 0, RollPurpose::HitCheck, 0, 0);
    }

    /**
     * A chi-square goodness-of-fit test against a uniform distribution.
     *
     * Six buckets, 60000 samples. The 99.9th percentile of the chi-square
     * distribution with 5 degrees of freedom is about 20.5, so a correct
     * generator clears this threshold essentially always, while modulo bias or
     * a broken mix would not.
     */
    public function testDistributionIsUniform(): void
    {
        $buckets = array_fill(0, 6, 0);
        $samples = 60000;

        for ($i = 0; $i < $samples; ++$i) {
            ++$buckets[DeterministicRng::below(self::SEED, $i, 0, RollPurpose::EffectApplication, 0, 6)];
        }

        $expected = $samples / 6;
        $chiSquare = 0.0;

        foreach ($buckets as $observed) {
            $chiSquare += (($observed - $expected) ** 2) / $expected;
        }

        self::assertLessThan(20.5, $chiSquare, sprintf(
            'Distribution is not uniform: chi-square %.2f over buckets %s',
            $chiSquare,
            json_encode($buckets),
        ));
    }

    public function testChanceHandlesCertainOutcomesWithoutDrawing(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertFalse(DeterministicRng::chance(self::SEED, $i, 0, RollPurpose::CriticalCheck, 0, 0));
            self::assertTrue(DeterministicRng::chance(self::SEED, $i, 0, RollPurpose::CriticalCheck, 0, 10000));
        }
    }

    public function testChanceApproximatesTheRequestedRate(): void
    {
        $samples = 20000;
        $hits = 0;

        for ($i = 0; $i < $samples; ++$i) {
            if (DeterministicRng::chance(self::SEED, $i, 0, RollPurpose::CriticalCheck, 0, 2500)) {
                ++$hits;
            }
        }

        $rate = $hits / $samples;

        self::assertGreaterThan(0.24, $rate);
        self::assertLessThan(0.26, $rate);
    }
}
