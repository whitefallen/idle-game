<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * How an encounter ended, from the players' perspective.
 *
 * A Draw means the round cap was reached with both sides alive. It is treated
 * as a design failure rather than a player failure: no rewards are granted and
 * the Vigor cost is refunded. See docs/combat.md section 4.
 */
enum Outcome: string
{
    case Victory = 'victory';
    case Defeat = 'defeat';
    case Draw = 'draw';
}
