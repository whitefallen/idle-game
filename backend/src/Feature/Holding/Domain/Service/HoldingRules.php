<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Service;

/**
 * Passive accrual.
 *
 * The Holding is the idle layer: elapsed time produces refinement materials and
 * a modest gold tithe. Combat is never simulated while a player is away — see
 * ADR-0003 and docs/idle.md.
 *
 * Every calculation here is closed-form and O(1). A player returning after six
 * months costs exactly what one returning after an hour costs, and there is no
 * scheduled job adding resources to anybody: state is (anchor, rate) and the
 * balance is derived on read (docs/idle.md rule T5).
 */
final class HoldingRules
{
    public const int SECONDS_PER_HOUR = 3600;

    /**
     * Production slots at level 1, and the ceiling.
     *
     * Two is enough for a real decision on the first day — a player picks which
     * of the available lines matters more — without the screen being mostly
     * empty placeholders.
     */
    public const int BASE_SLOTS = 2;

    public const int MAX_SLOTS = 7;

    /**
     * The levels at which the third through seventh slots unlock.
     *
     * docs/idle.md describes this as "one additional slot every 10 levels, to 7
     * at level 60". Even ten-level spacing actually reaches seven at level 50
     * and then leaves the last ten levels with nothing to give, so the final
     * two steps are stretched to land the maximum exactly where the design says
     * it lands. An explicit ladder rather than a formula, because that is what
     * the ladder is: five authored milestones, not an arithmetic sequence.
     */
    public const array SLOT_UNLOCK_LEVELS = [10, 20, 30, 45, 60];

    /**
     * The accrual cap: 12 hours at level 1, rising to 24.
     *
     * The cap bounds the value of absence, which is what keeps the active/idle
     * parity target in docs/game-bible.md section 7 achievable, and it gives a
     * returning player a reason to come back tomorrow rather than in a month.
     *
     * Production **stops** at the cap rather than overflowing into a secondary
     * resource; overflow defeats the purpose of having a cap.
     *
     * It cannot be extended by payment, ever. A purchasable cap makes paying
     * strictly more productive, which is the definition of pay-to-win in an
     * idle game. This is a monetisation constraint (docs/economy.md section 6),
     * not a current preference, which is why the only input here is level.
     */
    public const int BASE_CAP_SECONDS = 12 * self::SECONDS_PER_HOUR;

    public const int MAX_CAP_SECONDS = 24 * self::SECONDS_PER_HOUR;

    public const int LEVELS_PER_CAP_HOUR = 5;

    /** Gold tithe: `4 + 2 * level` per hour (tunable). */
    public const int TITHE_BASE_PER_HOUR = 4;

    public const int TITHE_PER_LEVEL_PER_HOUR = 2;

    /**
     * The multiplier a slot applies to its line's base rate, in basis points.
     *
     * Fixed at 1.0 until Holding upgrades exist (docs/idle.md section 6). It is
     * named rather than absent so that the upgrade work changes a value the
     * formula already reads, instead of introducing a factor into a formula
     * that never had one.
     */
    public const int SLOT_TIER_BASE_BP = 10000;

    private function __construct()
    {
    }

    /** How many production slots a character of this level has unlocked. */
    public static function slotsAt(int $level): int
    {
        $slots = self::BASE_SLOTS;

        foreach (self::SLOT_UNLOCK_LEVELS as $unlockLevel) {
            if ($level >= $unlockLevel) {
                ++$slots;
            }
        }

        return min(self::MAX_SLOTS, $slots);
    }

    /** The level at which a given slot index (0-based) unlocks. */
    public static function slotUnlockLevel(int $index): int
    {
        if ($index < self::BASE_SLOTS) {
            return 1;
        }

        return self::SLOT_UNLOCK_LEVELS[$index - self::BASE_SLOTS] ?? PHP_INT_MAX;
    }

    public static function capSecondsAt(int $level): int
    {
        $bonus = intdiv(max(1, $level), self::LEVELS_PER_CAP_HOUR) * self::SECONDS_PER_HOUR;

        return min(self::MAX_CAP_SECONDS, self::BASE_CAP_SECONDS + $bonus);
    }

    public static function tithePerHour(int $level): int
    {
        return self::TITHE_BASE_PER_HOUR + self::TITHE_PER_LEVEL_PER_HOUR * max(1, $level);
    }

    /** A production line's effective hourly rate, after the slot multiplier. */
    public static function ratePerHour(int $baseRatePerHour): int
    {
        return intdiv($baseRatePerHour * self::SLOT_TIER_BASE_BP, 10000);
    }

    /**
     * Accrues one line from elapsed time.
     *
     * Two properties matter more than the arithmetic:
     *
     * **The anchor advances by whole units only, never to `$now`.** Setting it
     * to now would discard the part-finished unit, so a player who claims every
     * few minutes would produce strictly less than one who claims once a day —
     * punishing attention in the one system that is supposed to reward absence.
     *
     * **Time beyond the cap is discarded, not banked.** When the elapsed time
     * exceeds the cap the anchor moves forward to exactly one cap-width ago
     * before anything is produced. Without that, every subsequent claim would
     * keep paying out a full cap from the same stale anchor.
     *
     * Elapsed time is clamped at zero: a clock adjustment or replication lag
     * must never produce a negative or wrapped award (docs/idle.md rule T4).
     *
     * @param int $ratePerHour Units produced per hour. Zero for an unassigned
     *                         slot, which accrues nothing and simply tracks now.
     * @param int $anchoredAt  Unix timestamp, server-set. Never client-supplied
     *                         (docs/idle.md rule T1).
     *
     * @return array{produced: int, anchoredAt: int}
     */
    public static function accrue(int $ratePerHour, int $anchoredAt, int $now, int $capSeconds): array
    {
        if ($ratePerHour <= 0) {
            return ['produced' => 0, 'anchoredAt' => $now];
        }

        $elapsed = max(0, $now - $anchoredAt);

        if ($elapsed > $capSeconds) {
            $anchoredAt = $now - $capSeconds;
            $elapsed = $capSeconds;
        }

        $produced = intdiv($elapsed * $ratePerHour, self::SECONDS_PER_HOUR);

        if ($produced <= 0) {
            return ['produced' => 0, 'anchoredAt' => $anchoredAt];
        }

        // Never more than the elapsed time: `produced` is a floor, so the cost
        // of the units produced is always at or below the time available. The
        // remainder stays on the anchor as progress towards the next unit.
        $consumed = intdiv($produced * self::SECONDS_PER_HOUR, $ratePerHour);

        return ['produced' => $produced, 'anchoredAt' => $anchoredAt + $consumed];
    }

    /**
     * Seconds until this line produces its next whole unit.
     *
     * For the client's countdown, so a player can see the Holding is working
     * rather than staring at a number that changes every five minutes for no
     * visible reason. Zero when a unit is already pending.
     */
    public static function secondsUntilNextUnit(int $ratePerHour, int $anchoredAt, int $now, int $capSeconds): int
    {
        if ($ratePerHour <= 0) {
            return 0;
        }

        $elapsed = min(max(0, $now - $anchoredAt), $capSeconds);
        $secondsPerUnit = intdiv(self::SECONDS_PER_HOUR, max(1, $ratePerHour));
        $remainder = $elapsed % max(1, $secondsPerUnit);

        return $elapsed >= $capSeconds ? 0 : max(0, $secondsPerUnit - $remainder);
    }

    /** When this line stops producing, so the client can say "full at …". */
    public static function fullAt(int $anchoredAt, int $capSeconds): int
    {
        return $anchoredAt + $capSeconds;
    }
}
