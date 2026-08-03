<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;

/**
 * Materialises a character as a combat participant.
 *
 * Derived stats are computed here and frozen into the snapshot, which is what
 * lets the same fight be replayed after the character levels up or re-equips.
 * The engine cannot tell a character from a monster: both arrive as a
 * Participant, so there is exactly one combat implementation.
 */
final class CharacterParticipantFactory
{
    public function create(Character $character): Participant
    {
        $stats = $character->derivedStats();

        return new Participant(
            id: $character->id()->toRfc4122(),
            definitionId: 'character',
            name: $character->name(),
            team: Team::Players,
            level: $character->level(),
            maxHealth: $stats->maxHealth,
            initiative: $stats->initiative,
            maxFocus: $stats->maxFocus,
            focusPerTurn: $stats->focusPerTurn,
            weaponBaseDamage: $stats->weaponBaseDamage,
            flatDamageBonus: $stats->flatDamageBonus,
            scalingBp: $stats->scalingBp,
            critChanceBp: $stats->critChanceBp,
            critPowerBp: $stats->critPowerBp,
            dodgeChanceBp: $stats->dodgeChanceBp,
            accuracyBp: $stats->accuracyBp,
            armourRating: $stats->armourRating,
            resistanceRatings: $stats->resistanceRatings,
            battlePlan: $character->battlePlan(),
            abilityIds: $character->abilityIds(),
        );
    }
}
