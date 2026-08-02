<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Repository;

use App\Feature\Combat\Domain\Model\Ability;

interface AbilityRepository
{
    /**
     * @return array<string, Ability> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the ability does not exist
     */
    public function get(string $id): Ability;

    /**
     * @param list<string> $ids
     *
     * @return array<string, Ability>
     */
    public function subset(array $ids): array;
}
