<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Holding\Domain\Service;

use App\Feature\Holding\Domain\Service\HoldingRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoldingRules::class)]
final class HoldingRulesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** Emberash: 12 an hour, so one unit every five minutes. */
    private const int RATE = 12;

    private const int CAP = 12 * 3600;

    public function testProducesWholeUnitsFromElapsedTime(): void
    {
        $result = HoldingRules::accrue(self::RATE, self::NOW - 3600, self::NOW, self::CAP);

        self::assertSame(12, $result['produced']);
    }

    public function testProducesNothingBeforeTheFirstWholeUnit(): void
    {
        $result = HoldingRules::accrue(self::RATE, self::NOW - 299, self::NOW, self::CAP);

        self::assertSame(0, $result['produced']);
        self::assertSame(
            self::NOW - 299,
            $result['anchoredAt'],
            'An anchor that produced nothing must not move, or the progress is lost.',
        );
    }

    /**
     * The property that makes an attentive player equal to an absent one.
     *
     * The anchor advances by whole units only. Were it set to "now" on every
     * claim, the part-finished unit would be discarded each time, so a player
     * claiming every minute would produce strictly less than one claiming once
     * a day — punishing attention in the one system built to reward absence.
     */
    public function testFrequentClaimsProduceExactlyAsMuchAsOneLateClaim(): void
    {
        $end = self::NOW + 6 * 3600;
        $anchor = self::NOW;
        $total = 0;

        // Claim every 70 seconds: never a whole unit at a time for a
        // five-minute line, and never aligned to one either.
        for ($at = self::NOW + 70; $at < $end; $at += 70) {
            $result = HoldingRules::accrue(self::RATE, $anchor, $at, self::CAP);
            $total += $result['produced'];
            $anchor = $result['anchoredAt'];
        }

        $total += HoldingRules::accrue(self::RATE, $anchor, $end, self::CAP)['produced'];

        $patient = HoldingRules::accrue(self::RATE, self::NOW, $end, self::CAP);

        self::assertSame(
            $patient['produced'],
            $total,
            'Claiming often must produce exactly what claiming once produces.',
        );
    }

    public function testProductionStopsAtTheCap(): void
    {
        $result = HoldingRules::accrue(self::RATE, self::NOW - 100 * 3600, self::NOW, self::CAP);

        self::assertSame(
            intdiv(self::CAP * self::RATE, 3600),
            $result['produced'],
            'A hundred hours away must pay exactly one cap, never a hundred hours.',
        );
    }

    /**
     * Time beyond the cap is discarded, not banked.
     *
     * Without moving the anchor forward to one cap-width ago, a player who
     * stayed away for a week could claim a full cap, wait a second, and claim a
     * full cap again — the stale anchor would still be a week behind.
     */
    public function testTimeBeyondTheCapIsDiscardedRatherThanBanked(): void
    {
        $first = HoldingRules::accrue(self::RATE, self::NOW - 100 * 3600, self::NOW, self::CAP);
        $second = HoldingRules::accrue(self::RATE, $first['anchoredAt'], self::NOW + 1, self::CAP);

        self::assertGreaterThan(0, $first['produced']);
        self::assertSame(0, $second['produced'], 'A second immediate claim must pay nothing.');
    }

    /**
     * Idle games are attacked through time. A clock adjustment or replication
     * lag must never produce a negative or wrapped award. See docs/idle.md T4.
     */
    public function testAnAnchorInTheFutureProducesNothing(): void
    {
        $result = HoldingRules::accrue(self::RATE, self::NOW + 86_400, self::NOW, self::CAP);

        self::assertSame(0, $result['produced']);
    }

    public function testAnUnassignedSlotProducesNothingAndBanksNothing(): void
    {
        $result = HoldingRules::accrue(0, self::NOW - 100 * 3600, self::NOW, self::CAP);

        self::assertSame(0, $result['produced']);
        self::assertSame(
            self::NOW,
            $result['anchoredAt'],
            'An idle slot tracks now, so assigning it later does not pay out a backlog it never earned.',
        );
    }

    #[DataProvider('slotLadder')]
    public function testSlotsUnlockOnTheAuthoredLadder(int $level, int $expected): void
    {
        self::assertSame($expected, HoldingRules::slotsAt($level));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function slotLadder(): iterable
    {
        yield 'level 1' => [1, 2];
        yield 'just before the third' => [9, 2];
        yield 'third slot' => [10, 3];
        yield 'fourth slot' => [20, 4];
        yield 'fifth slot' => [30, 5];
        yield 'the stretched gap' => [44, 5];
        yield 'sixth slot' => [45, 6];
        yield 'seventh slot' => [60, 7];
        yield 'beyond the ladder' => [999, HoldingRules::MAX_SLOTS];
    }

    public function testTheCapRisesWithLevelAndStopsAtTwentyFourHours(): void
    {
        self::assertSame(12 * 3600, HoldingRules::capSecondsAt(1));
        self::assertSame(24 * 3600, HoldingRules::capSecondsAt(60));
        self::assertSame(
            HoldingRules::MAX_CAP_SECONDS,
            HoldingRules::capSecondsAt(999),
            'The cap is bounded regardless of level, and by nothing else — it is never purchasable.',
        );
    }

    public function testTheCapNeverDecreasesWithLevel(): void
    {
        for ($level = 2; $level <= 60; ++$level) {
            self::assertGreaterThanOrEqual(
                HoldingRules::capSecondsAt($level - 1),
                HoldingRules::capSecondsAt($level),
                sprintf('Level %d must not cap lower than level %d.', $level, $level - 1),
            );
        }
    }

    public function testTheTitheScalesWithLevel(): void
    {
        self::assertSame(6, HoldingRules::tithePerHour(1));
        self::assertSame(124, HoldingRules::tithePerHour(60));
    }

    /**
     * The tithe must stay a minor faucet. If passive gold ever rivals active
     * gold, the Vigor cap stops constraining income and the parity contract in
     * docs/game-bible.md section 7 breaks. See docs/economy.md section 2.
     */
    public function testTheTitheStaysFarBelowEncounterGold(): void
    {
        $level = 30;
        $dailyTithe = HoldingRules::tithePerHour($level) * (HoldingRules::capSecondsAt($level) / 3600);

        // A patrol at this level pays roughly 5 * level, and the Vigor cap
        // allows about 24 of them a day.
        $dailyEncounterGold = 5 * $level * 24;

        self::assertLessThan(
            $dailyEncounterGold / 2,
            $dailyTithe,
            'Passive gold must remain well under half of active gold.',
        );
    }

    public function testCountsDownToTheNextUnit(): void
    {
        self::assertSame(300, HoldingRules::secondsUntilNextUnit(self::RATE, self::NOW, self::NOW, self::CAP));
        self::assertSame(1, HoldingRules::secondsUntilNextUnit(self::RATE, self::NOW - 299, self::NOW, self::CAP));
    }

    public function testCountdownIsZeroOnceProductionHasStopped(): void
    {
        self::assertSame(
            0,
            HoldingRules::secondsUntilNextUnit(self::RATE, self::NOW - 100 * 3600, self::NOW, self::CAP),
        );
    }
}
