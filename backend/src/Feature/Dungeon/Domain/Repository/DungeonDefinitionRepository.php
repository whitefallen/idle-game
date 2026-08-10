<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Repository;

use App\Feature\Dungeon\Domain\Model\DungeonDefinition;

interface DungeonDefinitionRepository
{
    /**
     * @return array<string, DungeonDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the dungeon does not exist
     */
    public function get(string $id): DungeonDefinition;
}
