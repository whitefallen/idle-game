<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Repository;

use App\Feature\Encounter\Domain\Model\MonsterDefinition;

interface MonsterRepository
{
    /**
     * @return array<string, MonsterDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the monster does not exist
     */
    public function get(string $id): MonsterDefinition;
}
