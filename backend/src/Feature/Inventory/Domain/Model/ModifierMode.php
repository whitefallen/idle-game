<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

enum ModifierMode: string
{
    case Flat = 'flat';

    /** A share of the item's own base value for that stat, in basis points. */
    case Percent = 'percent';
}
