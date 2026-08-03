<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Model;

/**
 * The five primary attributes. Structural: the set is fixed by design.
 *
 * See docs/progression.md section 2.
 */
enum Attribute: string
{
    case Strength = 'STR';
    case Dexterity = 'DEX';
    case Intelligence = 'INT';
    case Constitution = 'CON';
    case Luck = 'LUK';
}
