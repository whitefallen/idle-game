<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Model\AffixDefinition;

interface AffixRepository
{
    /**
     * @return array<string, AffixDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the affix does not exist
     */
    public function get(string $id): AffixDefinition;
}
