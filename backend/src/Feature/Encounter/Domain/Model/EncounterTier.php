<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Model;

/**
 * The reward class of an encounter.
 *
 * Multipliers are in basis points and match the table in
 * docs/progression.md section 1.
 */
enum EncounterTier: string
{
    case Patrol = 'patrol';
    case Elite = 'elite';
    case Boss = 'boss';

    public function experienceMultiplierBp(): int
    {
        return match ($this) {
            self::Patrol => 10000,
            self::Elite => 25000,
            self::Boss => 60000,
        };
    }
}
