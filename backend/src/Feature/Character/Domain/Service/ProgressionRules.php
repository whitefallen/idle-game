<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Service;

use InvalidArgumentException;

/**
 * The level, experience and point-budget curve.
 *
 * All integer arithmetic with truncating division, matching the combat engine's
 * convention so that progression and combat agree exactly on every value.
 *
 * The curve is quadratic rather than exponential on purpose: exponential XP
 * forces exponential rewards, which forces exponential stats, and within two
 * years the numbers stop fitting in a player's head and start stressing the
 * database. See docs/progression.md section 1.
 */
final class ProgressionRules
{
    public const int MAX_LEVEL = 60;

    public const int STARTING_POINTS = 10;

    public const int POINTS_PER_LEVEL = 5;

    private const int MAX_LOADOUT_SLOTS = 11;

    /** Level-difference falloff bounds, in basis points. */
    private const int FALLOFF_FLOOR_BP = 1000;

    private const int FALLOFF_CEILING_BP = 12500;

    private const int FALLOFF_PER_LEVEL_BP = 500;

    private function __construct()
    {
    }

    /**
     * Experience required to advance from $level to $level + 1.
     */
    public static function experienceToNextLevel(int $level): int
    {
        self::assertValidLevel($level);

        if ($level >= self::MAX_LEVEL) {
            return 0;
        }

        return 60 * $level * $level + 140 * $level;
    }

    /**
     * Total experience accumulated across all levels up to $level.
     */
    public static function cumulativeExperienceFor(int $level): int
    {
        self::assertValidLevel($level);

        $total = 0;

        for ($l = 1; $l < $level; ++$l) {
            $total += self::experienceToNextLevel($l);
        }

        return $total;
    }

    /**
     * Applies experience, returning the resulting level and remaining progress.
     *
     * Returns both because a single award can cross several levels, and the
     * caller needs to know how many were gained in order to emit one
     * PlayerLeveledUp event per level.
     *
     * @return array{level: int, experience: int, levelsGained: int}
     */
    public static function applyExperience(int $level, int $experience, int $awarded): array
    {
        self::assertValidLevel($level);

        if ($awarded < 0) {
            throw new InvalidArgumentException('Experience awards cannot be negative.');
        }

        $experience += $awarded;
        $levelsGained = 0;

        while ($level < self::MAX_LEVEL) {
            $required = self::experienceToNextLevel($level);

            if ($experience < $required) {
                break;
            }

            $experience -= $required;
            ++$level;
            ++$levelsGained;
        }

        // At the cap, surplus experience is discarded rather than banked. A
        // hidden buffer that silently drains into the next level on a cap raise
        // is impossible for a player to reason about.
        if ($level >= self::MAX_LEVEL) {
            $experience = 0;
        }

        return ['level' => $level, 'experience' => $experience, 'levelsGained' => $levelsGained];
    }

    /**
     * The falloff applied to rewards from encounters below the character's level.
     *
     * Makes farming trivial content inefficient without forbidding it: a player
     * helping a friend is not blocked, merely not rewarded for staying there.
     */
    public static function experienceScaleBp(int $characterLevel, int $encounterLevel): int
    {
        $delta = $encounterLevel - $characterLevel;

        return max(
            self::FALLOFF_FLOOR_BP,
            min(self::FALLOFF_CEILING_BP, 10000 + self::FALLOFF_PER_LEVEL_BP * $delta),
        );
    }

    public static function awardedExperience(int $baseExperience, int $characterLevel, int $encounterLevel): int
    {
        return intdiv(
            $baseExperience * self::experienceScaleBp($characterLevel, $encounterLevel),
            10000,
        );
    }

    /**
     * Total attribute points a character of this level has been granted.
     */
    public static function totalAttributePointsAt(int $level): int
    {
        self::assertValidLevel($level);

        return self::STARTING_POINTS + ($level - 1) * self::POINTS_PER_LEVEL;
    }

    public static function loadoutSlotsAt(int $level): int
    {
        self::assertValidLevel($level);

        return min(self::MAX_LOADOUT_SLOTS, 2 + intdiv($level, 6));
    }

    /**
     * Gold cost to reallocate attributes.
     *
     * Priced as a routine expense rather than a penalty: build iteration is the
     * fun part, and the cost exists to be a wealth sink, not a deterrent.
     * See docs/economy.md section 4.
     */
    public static function respecCost(int $level): int
    {
        self::assertValidLevel($level);

        return 150 * $level + 5 * $level * $level;
    }

    private static function assertValidLevel(int $level): void
    {
        if ($level < 1 || $level > self::MAX_LEVEL) {
            throw new InvalidArgumentException(
                sprintf('Level must be within [1, %d], got %d.', self::MAX_LEVEL, $level),
            );
        }
    }
}
