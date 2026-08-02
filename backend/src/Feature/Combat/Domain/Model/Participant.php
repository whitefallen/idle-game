<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * A fully materialised combatant snapshot.
 *
 * Derived values arrive already computed. The engine deliberately does not know
 * the progression formulas that produced them: those belong to the Character
 * feature, and keeping them out means a balance change to health or crit does
 * not touch combat, and combat tests do not depend on progression rules.
 *
 * Because this snapshot is persisted with every encounter, a fight stays
 * reproducible even after the character re-equips or levels up. See
 * docs/combat.md section 1.2.
 */
final readonly class Participant
{
    /**
     * @param array<string, int> $resistanceRatings Keyed by DamageSchool value.
     * @param list<string>       $abilityIds
     */
    public function __construct(
        public string $id,
        public string $definitionId,
        public string $name,
        public Team $team,
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
        public BattlePlan $battlePlan,
        public array $abilityIds,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('A participant requires an id.');
        }

        if ($maxHealth < 1) {
            throw new InvalidArgumentException(
                sprintf('Participant "%s" must have at least 1 maximum health.', $id),
            );
        }

        if ($level < 1) {
            throw new InvalidArgumentException(sprintf('Participant "%s" must be at least level 1.', $id));
        }

        if ($abilityIds === []) {
            throw new InvalidArgumentException(
                sprintf('Participant "%s" has no abilities and could never act.', $id),
            );
        }

        foreach ($resistanceRatings as $school => $rating) {
            if (DamageSchool::tryFrom((string) $school) === null) {
                throw new InvalidArgumentException(sprintf('Unknown damage school "%s".', (string) $school));
            }

            if ($rating < 0) {
                throw new InvalidArgumentException('Resistance ratings cannot be negative.');
            }
        }
    }

    public function resistanceRatingFor(DamageSchool $school): int
    {
        return $this->resistanceRatings[$school->value] ?? 0;
    }

    public function knowsAbility(string $abilityId): bool
    {
        return in_array($abilityId, $this->abilityIds, true);
    }
}
