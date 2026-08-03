<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * Prefix and suffix are separate pools so that offensive and defensive
 * modifiers cannot both be maximised on one item without cost.
 */
enum AffixKind: string
{
    case Prefix = 'prefix';
    case Suffix = 'suffix';
}
