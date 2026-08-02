<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * The effect primitives abilities and item properties are composed from.
 *
 * Disciplines and legendary item properties are data, not code: each is a
 * parameterised composition of these primitives. Keeping the set small is what
 * makes "adding a discipline is a content change" true rather than aspirational.
 */
enum EffectKind: string
{
    /** Deals its magnitude as damage at the start of each round. */
    case DamageOverTime = 'damage_over_time';

    /** Restores its magnitude as health at the start of each round. */
    case HealOverTime = 'heal_over_time';

    /**
     * Adjusts the bearer's outgoing damage by its magnitude in basis points.
     * Positive values buff, negative values debuff. Applied at step 4 of the
     * damage pipeline, where modifiers stack additively with one another.
     */
    case DamageModifier = 'damage_modifier';
}
