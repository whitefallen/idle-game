<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Model;

/**
 * The values computed from a character's level, attributes and equipment.
 *
 * Never persisted. Recomputing on read costs microseconds and removes an entire
 * class of bug: a stored derived value that has silently drifted from the
 * inputs that produced it. The one documented exception is the denormalised
 * ranking column, which is advisory only — see ADR-0006.
 */
final readonly class DerivedStats
{
    /**
     * @param array<string, int> $resistanceRatings
     */
    public function __construct(
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maxHealth' => $this->maxHealth,
            'initiative' => $this->initiative,
            'maxFocus' => $this->maxFocus,
            'focusPerTurn' => $this->focusPerTurn,
            'weaponBaseDamage' => $this->weaponBaseDamage,
            'flatDamageBonus' => $this->flatDamageBonus,
            'scalingBp' => $this->scalingBp,
            'critChanceBp' => $this->critChanceBp,
            'critPowerBp' => $this->critPowerBp,
            'dodgeChanceBp' => $this->dodgeChanceBp,
            'accuracyBp' => $this->accuracyBp,
            'armourRating' => $this->armourRating,
            'resistanceRatings' => $this->resistanceRatings,
        ];
    }
}
