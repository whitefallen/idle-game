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

    private function __construct()
    {
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
