<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Model;

use InvalidArgumentException;

final readonly class EncounterDefinition
{
    /**
     * @param list<string> $monsterIds May repeat: three of the same monster is
     *                                 a legitimate encounter.
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $level,
        public EncounterTier $tier,
        public int $vigorCost,
        public int $requiredLevel,
        public array $monsterIds,
        public string $dropTableId,
    ) {
        if ($monsterIds === []) {
            throw new InvalidArgumentException(
                sprintf('Encounter "%s" has no monsters.', $id),
            );
        }

        if ($vigorCost < 1) {
            throw new InvalidArgumentException(
                sprintf('Encounter "%s" must cost at least 1 Vigor.', $id),
            );
        }
    }

    /**
     * Base experience before the level-difference falloff, per
     * docs/progression.md section 1.
     */
    public function baseExperience(): int
    {
        return intdiv((12 * $this->level + 40) * $this->tier->experienceMultiplierBp(), 10000);
    }
}
