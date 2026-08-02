<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Model;

use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;

/**
 * A monster, as authored in content.
 *
 * Monster stats are authored directly rather than derived from attributes and
 * equipment: a monster has no gear, no allocation and no respec, so an
 * attribute layer would be indirection with no payoff. Player-side stats are
 * derived by the Character feature and arrive through the same
 * {@see Participant} snapshot, which is why the engine cannot tell the two apart.
 */
final readonly class MonsterDefinition
{
    /**
     * @param array<string, int> $resistanceRatings Keyed by DamageSchool value.
     * @param list<string>       $abilityIds
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $level,
        public int $maxHealth,
        public int $initiative,
        public int $maxFocus,
        public int $focusPerTurn,
        public int $weaponBaseDamage,
        public int $flatDamageBonus,
        public int $scalingBp,
        public int $critChanceBp,
        public int $critPowerBp,
        public int $dodgeChanceBp,
        public int $accuracyBp,
        public int $armourRating,
        public array $resistanceRatings,
        public array $abilityIds,
        public BattlePlan $battlePlan,
    ) {
    }

    /**
     * Materialises this definition as a combat participant.
     *
     * The instance id is supplied by the caller rather than generated here, so
     * that the engine stays free of ambient state and so the same monster can
     * appear several times in one encounter with distinct, stable identities.
     */
    public function toParticipant(string $participantId): Participant
    {
        return new Participant(
            id: $participantId,
            definitionId: $this->id,
            name: $this->localisationKey,
            team: Team::Enemies,
            level: $this->level,
            maxHealth: $this->maxHealth,
            initiative: $this->initiative,
            maxFocus: $this->maxFocus,
            focusPerTurn: $this->focusPerTurn,
            weaponBaseDamage: $this->weaponBaseDamage,
            flatDamageBonus: $this->flatDamageBonus,
            scalingBp: $this->scalingBp,
            critChanceBp: $this->critChanceBp,
            critPowerBp: $this->critPowerBp,
            dodgeChanceBp: $this->dodgeChanceBp,
            accuracyBp: $this->accuracyBp,
            armourRating: $this->armourRating,
            resistanceRatings: $this->resistanceRatings,
            battlePlan: $this->battlePlan,
            abilityIds: $this->abilityIds,
        );
    }
}
