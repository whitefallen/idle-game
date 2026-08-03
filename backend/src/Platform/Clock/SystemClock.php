<?php

declare(strict_types=1);

namespace App\Platform\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        // Always UTC. A naive or locally-zoned timestamp in a game with a
        // global player base is a defect waiting for a DST boundary.
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
