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
     * Every discipline a character of this level, plus these owned ids, has
     * available.
     *
     * Level-milestone ownership stays derived rather than stored — a pure
     * function of the level, no grant step, no row, no backfill, no drift.
     * `$ownedIds` is the stored half: quest, dungeon and reputation sourced
     * disciplines, which have no level to derive from and must be looked up
     * (see CharacterDisciplineRepository). Passing an empty array is exactly
     * "level-derived only," so every existing caller before this stored half
     * existed needed no change.
     *
     * @param list<string> $ownedIds
     *
     * @return array<string, Discipline> Keyed by id, ordered by id.
     */
    public function availableAtLevel(int $level, array $ownedIds = []): array;

    /**
     * The ability ids a character of this level, plus these owned discipline
     * ids, is allowed to slot.
     *
     * @param list<string> $ownedIds
     *
     * @return list<string> Ordered, without duplicates.
     */
    public function grantedAbilityIdsAtLevel(int $level, array $ownedIds = []): array;
}
