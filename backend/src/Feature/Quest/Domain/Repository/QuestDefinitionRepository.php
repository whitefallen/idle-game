<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Repository;

use App\Feature\Quest\Domain\Model\QuestDefinition;

interface QuestDefinitionRepository
{
    /**
     * @return array<string, QuestDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the quest does not exist
     */
    public function get(string $id): QuestDefinition;
}
