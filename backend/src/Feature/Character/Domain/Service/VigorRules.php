<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Service;

/**
 * Vigor regeneration.
 *
 * Vigor is the action currency and the counterweight that stops active play
 * from dominating: it regenerates on a fixed schedule and is capped, so daily
 * encounter throughput has a ceiling no amount of play can exceed. That ceiling
 * is also the strongest anti-inflation and anti-botting property in the design.
 * See docs/idle.md section 4 and docs/economy.md section 5.
 *
 * State is (current, lastTickAt) and the balance is derived on read. There is no
 * scheduled job adding Vigor to players: a cron incrementer would be a
 * correctness liability (missed runs, double runs, drift) and an O(players)
 * cost for no benefit. See docs/idle.md rule T5.
 */
final class VigorRules
{
    public const int CAP = 120;

    public const int SECONDS_PER_POINT = 360;

    /**
     * The minimum interval between two Vigor-spending activities (tunable).
     *
     * A character runs **one Vigor-spending activity at a time**: a new one may
     * only begin once the previous has resolved and this interval has elapsed.
     *
     * This does not reduce daily throughput — the Vigor cap already does that,
     * and it remains the only ceiling (docs/game-bible.md section 7). What the
     * gate controls is *pacing*: it stops a pool being emptied in a single
     * burst of clicks, which matters for three reasons.
     *
     * 1. It makes each spend a decision rather than a reflex. The design's
     *    read-and-adjust beat — read the log, change the plan, fight again —
     *    cannot happen if twelve fights resolve before the first log is open.
     * 2. It bounds the cost a single account can impose on the server. Every
     *    encounter resolves synchronously inside its request, so an unthrottled
     *    client can issue a full pool's worth of combat simulations as fast as
     *    the network allows.
     * 3. It makes "am I already doing something?" a real, inspectable state,
     *    which the longer-running activities the design anticipates — dungeons,
     *    expeditions, arena defence — will need to be exclusive against anyway.
     *
     * Kept deliberately short. The gate is a pacing device, not a punishment,
     * and a player who wants to spend a full pool should still be able to do so
     * inside a single sitting — twelve patrols cost 24 seconds of gating.
     *
     * Two seconds is chosen as the headroom the client needs to present a
     * result before the next action becomes available: the replay opens, the
     * rewards land, and the fight button re-enables slightly after rather than
     * during. Below roughly a second the gate would stop being perceptible and
     * would only be doing the anti-burst job; above a few seconds it would
     * start reading as a cooldown, which it is not.
     */
    public const int ACTIVITY_GATE_SECONDS = 2;

    private function __construct()
    {
    }

    /**
     * The moment the next Vigor-spending activity may begin.
     *
     * @param int|null $lastSpendAt Unix timestamp of the last spend, or null
     *                              when the character has never spent any.
     */
    public static function activityReadyAt(?int $lastSpendAt): int
    {
        return $lastSpendAt === null ? 0 : $lastSpendAt + self::ACTIVITY_GATE_SECONDS;
    }

    /**
     * Whether a new Vigor-spending activity may begin.
     *
     * A `$now` earlier than the last spend — a clock adjustment, or replication
     * lag between the writer and a reader — is treated as "not ready" rather
     * than wrapping into a negative interval that would open the gate. Same
     * reasoning as the clamp in {@see regenerate()}; see docs/idle.md rule T4.
     */
    public static function canStartActivity(?int $lastSpendAt, int $now): bool
    {
        return $now >= self::activityReadyAt($lastSpendAt);
    }

    /**
     * How long until the gate opens, for the countdown the client renders.
     * Zero when an activity may begin now.
     */
    public static function secondsUntilReady(?int $lastSpendAt, int $now): int
    {
        return max(0, self::activityReadyAt($lastSpendAt) - $now);
    }

    /**
     * Regenerates Vigor from elapsed time.
     *
     * The anchor advances by whole points only, never to "now": setting it to
     * now on every read would discard the partial progress since the last
     * point, so a player refreshing frequently would regenerate more slowly
     * than one who did not.
     *
     * @param int $lastTickAt Unix timestamp, server-set. Never client-supplied.
     * @param int $now        Unix timestamp from the server clock.
     *
     * @return array{current: int, tickedAt: int}
     */
    public static function regenerate(int $current, int $lastTickAt, int $now): array
    {
        $current = max(0, min(self::CAP, $current));

        if ($current >= self::CAP) {
            return ['current' => self::CAP, 'tickedAt' => $now];
        }

        // Clamped at zero: a clock adjustment or replication lag must never
        // produce negative elapsed time, and never a negative or wrapped award.
        // See docs/idle.md rule T4.
        $elapsed = max(0, $now - $lastTickAt);
        $gained = intdiv($elapsed, self::SECONDS_PER_POINT);

        if ($gained <= 0) {
            return ['current' => $current, 'tickedAt' => $lastTickAt];
        }

        $regenerated = min(self::CAP, $current + $gained);

        return [
            'current' => $regenerated,
            'tickedAt' => $regenerated >= self::CAP
                ? $now
                : $lastTickAt + $gained * self::SECONDS_PER_POINT,
        ];
    }

    /**
     * When the pool will next be full, so the client can render a countdown
     * from a single response without polling. See docs/api.md section 7.
     */
    public static function fullAt(int $current, int $lastTickAt): int
    {
        if ($current >= self::CAP) {
            return $lastTickAt;
        }

        return $lastTickAt + (self::CAP - $current) * self::SECONDS_PER_POINT;
    }

    public static function secondsUntilNextPoint(int $current, int $lastTickAt, int $now): int
    {
        if ($current >= self::CAP) {
            return 0;
        }

        return max(0, ($lastTickAt + self::SECONDS_PER_POINT) - $now);
    }
}
