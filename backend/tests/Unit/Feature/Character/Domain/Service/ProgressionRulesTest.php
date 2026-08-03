<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Character\Domain\Service;

use App\Feature\Character\Domain\Service\ProgressionRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressionRules::class)]
final class ProgressionRulesTest extends TestCase
{
    /**
     * Pinned against the published table in docs/progression.md section 1. The
     * document is the specification; a mismatch means one of the two is wrong.
     */
    public function testExperienceCurveMatchesTheDocumentedTable(): void
    {
        $expected = [
            1 => 200,
            5 => 2_200,
            10 => 7_400,
            20 => 26_800,
            30 => 58_200,
            45 => 127_800,
            60 => 0, // At the cap there is no next level.
        ];

        foreach ($expected as $level => $required) {
            self::assertSame($required, ProgressionRules::experienceToNextLevel($level), 'level ' . $level);
        }
    }

    public function testCumulativeExperienceMatchesTheDocumentedTable(): void
    {
        self::assertSame(0, ProgressionRules::cumulativeExperienceFor(1));
        self::assertSame(3_200, ProgressionRules::cumulativeExperienceFor(5));
        self::assertSame(23_400, ProgressionRules::cumulativeExperienceFor(10));
        self::assertSame(174_800, ProgressionRules::cumulativeExperienceFor(20));
        self::assertSame(574_200, ProgressionRules::cumulativeExperienceFor(30));
        self::assertSame(1_900_800, ProgressionRules::cumulativeExperienceFor(45));
        self::assertSame(4_460_400, ProgressionRules::cumulativeExperienceFor(60));
    }

    /**
     * The curve is quadratic rather than exponential precisely so that the
     * ratio between consecutive levels approaches one: late levels should feel
     * like steady work, not a wall.
     */
    public function testCurveFlattensRatherThanExploding(): void
    {
        $ratioEarly = ProgressionRules::experienceToNextLevel(10) / ProgressionRules::experienceToNextLevel(9);
        $ratioLate = ProgressionRules::experienceToNextLevel(59) / ProgressionRules::experienceToNextLevel(58);

        self::assertLessThan($ratioEarly, $ratioLate);
        self::assertLessThan(1.1, $ratioLate);
    }

    public function testExperienceAwardCanCrossSeveralLevels(): void
    {
        $result = ProgressionRules::applyExperience(1, 0, 10_000);

        self::assertGreaterThan(1, $result['levelsGained']);
        self::assertSame($result['level'], 1 + $result['levelsGained']);
        self::assertLessThan(
            ProgressionRules::experienceToNextLevel($result['level']),
            $result['experience'],
        );
    }

    public function testExperienceIsDiscardedAtTheLevelCap(): void
    {
        $result = ProgressionRules::applyExperience(ProgressionRules::MAX_LEVEL, 0, 5_000_000);

        self::assertSame(ProgressionRules::MAX_LEVEL, $result['level']);
        self::assertSame(0, $result['experience'], 'A hidden buffer at the cap is impossible to reason about.');
        self::assertSame(0, $result['levelsGained']);
    }

    public function testNegativeExperienceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProgressionRules::applyExperience(5, 0, -1);
    }

    public function testLevelDifferenceFalloffIsClamped(): void
    {
        // Fighting far below level bottoms out rather than reaching zero, so
        // helping a friend is unrewarding but never punished.
        self::assertSame(1_000, ProgressionRules::experienceScaleBp(60, 1));

        // And fighting far above is capped, so a lucky carry cannot skip tiers.
        self::assertSame(12_500, ProgressionRules::experienceScaleBp(1, 60));

        self::assertSame(10_000, ProgressionRules::experienceScaleBp(10, 10));
        self::assertSame(7_500, ProgressionRules::experienceScaleBp(10, 5));
        self::assertSame(5_000, ProgressionRules::experienceScaleBp(20, 10));
    }

    public function testAwardedExperienceAppliesTheFalloff(): void
    {
        self::assertSame(100, ProgressionRules::awardedExperience(100, 10, 10));
        self::assertSame(75, ProgressionRules::awardedExperience(100, 10, 5));
        self::assertSame(10, ProgressionRules::awardedExperience(100, 60, 1));
    }

    public function testAttributePointBudget(): void
    {
        self::assertSame(10, ProgressionRules::totalAttributePointsAt(1));
        self::assertSame(55, ProgressionRules::totalAttributePointsAt(10));
        self::assertSame(305, ProgressionRules::totalAttributePointsAt(60));
    }

    public function testLoadoutSlotsGrowAndAreCapped(): void
    {
        self::assertSame(2, ProgressionRules::loadoutSlotsAt(1));
        self::assertSame(3, ProgressionRules::loadoutSlotsAt(6));
        self::assertSame(11, ProgressionRules::loadoutSlotsAt(60));

        // Monotonic: a level-up must never reduce a character's options.
        $previous = 0;
        for ($level = 1; $level <= ProgressionRules::MAX_LEVEL; ++$level) {
            $slots = ProgressionRules::loadoutSlotsAt($level);
            self::assertGreaterThanOrEqual($previous, $slots);
            $previous = $slots;
        }
    }

    /**
     * Respec is priced as a routine expense, not a penalty: roughly an hour of
     * level-appropriate income. See docs/economy.md section 4.
     */
    public function testRespecCostMatchesTheDocumentedFigures(): void
    {
        self::assertSame(5_000, ProgressionRules::respecCost(20));
        self::assertSame(14_000, ProgressionRules::respecCost(40));
        self::assertSame(27_000, ProgressionRules::respecCost(60));
    }

    public function testLevelsOutsideTheValidRangeAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProgressionRules::experienceToNextLevel(ProgressionRules::MAX_LEVEL + 1);
    }
}
