<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Rng;

/**
 * Stable identifiers for each site in the engine that consumes randomness.
 *
 * The integer values are part of the persisted determinism contract: they are
 * mixed into every roll's coordinates, so changing one silently changes the
 * outcome of every stored replay that used it.
 *
 * Rules, which are absolute:
 *
 *  - Never renumber a case.
 *  - Never reuse the value of a removed case. Retire it by leaving a comment.
 *  - New cases take the next free integer, which is always safe: because
 *    randomness is counter-based rather than sequential, adding a roll site
 *    cannot disturb the values drawn at any existing one.
 *
 * See docs/combat.md section 3.1.
 */
enum RollPurpose: int
{
    case HitCheck = 1;
    case CriticalCheck = 2;
    case TargetSelection = 3;
    case EffectApplication = 4;
}
