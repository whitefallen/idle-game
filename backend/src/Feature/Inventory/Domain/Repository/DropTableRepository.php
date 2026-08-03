<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Model\DropTable;

interface DropTableRepository
{
    /**
     * @return array<string, DropTable> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the table does not exist
     */
    public function get(string $id): DropTable;
}
