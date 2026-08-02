<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * The damage schools a resistance can apply to.
 *
 * Three schools, deliberately. Each additional school multiplies the gear
 * itemisation space and the balancing surface, and the value of a fourth is
 * hard to argue before the first three are proven in play.
 */
enum DamageSchool: string
{
    case Physical = 'physical';
    case Arcane = 'arcane';
    case Nature = 'nature';
}
