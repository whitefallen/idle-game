<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Model;

/**
 * How a discipline is acquired.
 *
 * There is deliberately no `Drop` case, and adding one would be a design
 * change rather than a feature. Gating build-defining progression behind a roll
 * converts strategy into lottery participation, which
 * docs/progression.md section 4.2 records as the primary complaint pattern in
 * comparable games.
 */
enum DisciplineSource: string
{
    /** Granted purely by reaching a level. The baseline kit. */
    case Level = 'level';

    case Quest = 'quest';

    case Dungeon = 'dungeon';

    case Reputation = 'reputation';

    /**
     * Whether ownership follows from the character's level alone.
     *
     * True for exactly one case today, and that is what lets ownership be
     * derived rather than stored: a level-milestone discipline needs no row,
     * no grant step and no backfill. The other sources will need persistence
     * when the systems that grant them exist.
     */
    public function isDerivableFromLevel(): bool
    {
        return $this === self::Level;
    }
}
