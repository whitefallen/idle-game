<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Character\Domain\Service;

use App\Feature\Character\Domain\Service\VigorRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VigorRules::class)]
final class VigorRulesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    public function testRegeneratesOnePointPerInterval(): void
    {
        $result = VigorRules::regenerate(0, self::NOW - VigorRules::SECONDS_PER_POINT, self::NOW);

        self::assertSame(1, $result['current']);
    }

    /**
     * The property that makes frequent polling safe.
     *
     * The anchor advances by whole points only. If it were set to "now" on every
     * read, the partial progress since the last point would be discarded, so a
     * player refreshing every minute would regenerate strictly more slowly than
     * one who left the tab alone — a bug that is invisible in a single request
     * and infuriating over a day.
     */
    public function testPartialProgressIsNotLostByRepeatedReads(): void
    {
        $interval = VigorRules::SECONDS_PER_POINT;
        $start = self::NOW;
        $current = 0;
        $anchor = $start;

        // Poll every tenth of an interval for ten intervals.
        for ($step = 1; $step <= 100; ++$step) {
            $now = $start + intdiv($step * $interval, 10);
            $result = VigorRules::regenerate($current, $anchor, $now);
            $current = $result['current'];
            $anchor = $result['tickedAt'];
        }

        self::assertSame(10, $current, 'Ten intervals must yield ten points regardless of polling.');
    }

    public function testRegenerationStopsAtTheCap(): void
    {
        $result = VigorRules::regenerate(0, self::NOW - VigorRules::SECONDS_PER_POINT * 10_000, self::NOW);

        self::assertSame(VigorRules::CAP, $result['current']);
        self::assertSame(self::NOW, $result['tickedAt'], 'A full pool anchors to now.');
    }

    /**
     * Idle games are attacked through time. A clock adjustment or replication
     * lag must never produce a negative or wrapped award.
     * See docs/idle.md rule T4.
     */
    public function testNegativeElapsedTimeIsClamped(): void
    {
        $result = VigorRules::regenerate(20, self::NOW + 86_400, self::NOW);

        self::assertSame(20, $result['current']);
        self::assertSame(self::NOW + 86_400, $result['tickedAt']);
    }

    public function testSubIntervalElapsedGrantsNothingAndKeepsTheAnchor(): void
    {
        $anchor = self::NOW - (VigorRules::SECONDS_PER_POINT - 1);
        $result = VigorRules::regenerate(5, $anchor, self::NOW);

        self::assertSame(5, $result['current']);
        self::assertSame($anchor, $result['tickedAt']);
    }

    public function testValuesOutsideTheValidRangeAreClamped(): void
    {
        self::assertSame(VigorRules::CAP, VigorRules::regenerate(9_999, self::NOW, self::NOW)['current']);
        self::assertSame(0, VigorRules::regenerate(-50, self::NOW, self::NOW)['current']);
    }

    public function testFullAtProjectsTheMomentTheCapIsReached(): void
    {
        $expected = self::NOW + (VigorRules::CAP - 10) * VigorRules::SECONDS_PER_POINT;

        self::assertSame($expected, VigorRules::fullAt(10, self::NOW));
        self::assertSame(self::NOW, VigorRules::fullAt(VigorRules::CAP, self::NOW));
    }

    public function testSecondsUntilNextPointNeverGoesNegative(): void
    {
        self::assertSame(0, VigorRules::secondsUntilNextPoint(VigorRules::CAP, self::NOW, self::NOW));
        self::assertSame(0, VigorRules::secondsUntilNextPoint(5, self::NOW - 100_000, self::NOW));
        self::assertSame(
            VigorRules::SECONDS_PER_POINT,
            VigorRules::secondsUntilNextPoint(5, self::NOW, self::NOW),
        );
    }

    /**
     * The parity contract in docs/game-bible.md section 7 depends on the daily
     * ceiling being real: twelve hours from empty to full, and no way to exceed
     * roughly 24 patrol encounters a day.
     */
    public function testDailyThroughputMatchesTheDesignedCeiling(): void
    {
        $secondsToFill = VigorRules::CAP * VigorRules::SECONDS_PER_POINT;

        self::assertSame(12 * 3600, $secondsToFill, 'Twelve hours from empty to full.');

        $dailyRegeneration = intdiv(86_400, VigorRules::SECONDS_PER_POINT);

        self::assertSame(240, $dailyRegeneration);
        self::assertSame(24, intdiv($dailyRegeneration, 10), 'Twenty-four patrols per day at 10 Vigor each.');
    }
}
