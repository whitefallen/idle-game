<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Model\ItemDefinition;

interface ItemDefinitionRepository
{
    /**
     * @return array<string, ItemDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the definition does not exist
     */
    public function get(string $id): ItemDefinition;

    /**
     * Definitions tagged as members of a drop pool.
     *
     * @return list<ItemDefinition>
     */
    public function inPool(string $pool): array;
}
