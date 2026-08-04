<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Repository;

use App\Feature\Character\Domain\Model\Discipline;

interface DisciplineRepository
{
    /**
     * @return array<string, Discipline> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the discipline does not exist
     */
    public function get(string $id): Discipline;

    /**
     * Every discipline a character of this level owns.
     *
     * Ownership is derived rather than stored, because every discipline today
     * is granted by a level milestone and that is a pure function of the level.
     * No grant step, no row, no backfill, and no way for the stored set to
     * drift from the rules. When quest, dungeon and reputation sources arrive
     * they will need persistence, and this method becomes the union of the
     * derived set and the stored one.
     *
     * @return array<string, Discipline> Keyed by id, ordered by id.
     */
    public function availableAtLevel(int $level): array;

    /**
     * The ability ids a character of this level is allowed to slot.
     *
     * @return list<string> Ordered, without duplicates.
     */
    public function grantedAbilityIdsAtLevel(int $level): array;
}
