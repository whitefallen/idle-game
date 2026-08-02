<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * Which side of an encounter a participant fights for.
 *
 * The engine is agnostic about what a team represents: an arena defence and a
 * monster patrol both resolve as Players versus Enemies. Keeping this binary
 * avoids a faction system the game does not need.
 */
enum Team: string
{
    case Players = 'players';
    case Enemies = 'enemies';

    public function opposing(): self
    {
        return $this === self::Players ? self::Enemies : self::Players;
    }
}
