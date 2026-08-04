<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Model;

use InvalidArgumentException;

/**
 * An unlockable entry in the ability catalogue — the third progression axis,
 * after level and gear. See docs/progression.md section 4.
 *
 * The discipline is the *unlock*; the ability is what it grants. Keeping the
 * two separate is what allows a later reputation vendor to offer an alternative
 * discipline granting a variant of an ability the player already has, without
 * either needing a special case in code.
 *
 * It is also what distinguishes a player ability from a monster one: an ability
 * is player-usable exactly when some discipline grants it. That test lives in
 * data rather than in a naming convention or an `is_monster` flag, so adding a
 * monster ability can never accidentally hand it to players.
 */
final readonly class Discipline
{
    public function __construct(
        public string $id,
        public string $localisationKey,
        public string $abilityId,
        public DisciplineSource $source,
        /** The level that grants it, for level-milestone disciplines only. */
        public ?int $unlockLevel = null,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('A discipline requires an id.');
        }

        if ($abilityId === '') {
            throw new InvalidArgumentException(
                sprintf('Discipline "%s" must grant an ability.', $id),
            );
        }

        if ($source->isDerivableFromLevel() && $unlockLevel === null) {
            throw new InvalidArgumentException(
                sprintf('Discipline "%s" is granted by level, so it needs an unlock level.', $id),
            );
        }

        if (!$source->isDerivableFromLevel() && $unlockLevel !== null) {
            throw new InvalidArgumentException(
                sprintf('Discipline "%s" is granted by %s, so an unlock level is meaningless.', $id, $source->value),
            );
        }
    }

    public function isAvailableAt(int $level): bool
    {
        return $this->source->isDerivableFromLevel() && $level >= (int) $this->unlockLevel;
    }
}
