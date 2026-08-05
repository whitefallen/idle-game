<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Model\MaterialDefinition;
use InvalidArgumentException;

/**
 * The material catalogue, read from content.
 *
 * Published as a read model so other features can resolve a material without
 * reaching into Inventory's application services: the Holding needs to know
 * which lines exist and what they produce. See docs/architecture.md section 3.1.
 */
interface MaterialRepository
{
    /**
     * @return array<string, MaterialDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws InvalidArgumentException when no such material is defined
     */
    public function get(string $id): MaterialDefinition;

    /**
     * The lines a character of this level may assign to a production slot.
     *
     * @return array<string, MaterialDefinition>
     */
    public function producibleAtLevel(int $level): array;
}
