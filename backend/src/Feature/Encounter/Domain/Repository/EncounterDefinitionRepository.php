<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Repository;

use App\Feature\Encounter\Domain\Model\EncounterDefinition;

interface EncounterDefinitionRepository
{
    /**
     * @return array<string, EncounterDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the encounter does not exist
     */
    public function get(string $id): EncounterDefinition;

    /**
     * Encounters a character of the given level is permitted to attempt.
     *
     * @return array<string, EncounterDefinition>
     */
    public function availableAtLevel(int $level): array;
}
