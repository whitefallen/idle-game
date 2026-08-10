<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Entity;

/**
 * The lifecycle of one character's attempt at one quest.
 *
 * A row is reused across attempts rather than accumulating a history row per
 * attempt: a failed attempt cost nothing (no Vigor, only time already spent),
 * so there is nothing to preserve beyond the fact that it happened, which
 * AuditLogger already records.
 */
enum QuestRunStatus: string
{
    case Active = 'active';

    /**
     * The simulated fight was lost or drew. Not terminal — accepting the same
     * quest again is allowed and overwrites this row, because nothing was
     * spent to reach this state.
     */
    case Failed = 'failed';

    /**
     * The fixed, one-time reward has been paid out. Terminal: the quest
     * cannot be repeated. See docs/economy.md section 2.
     */
    case Claimed = 'claimed';
}
