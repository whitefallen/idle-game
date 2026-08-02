<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Repository;

use App\Feature\Combat\Domain\Model\EffectDefinition;

interface EffectRepository
{
    /**
     * @return array<string, EffectDefinition> Keyed by id, ordered by id.
     */
    public function all(): array;

    public function has(string $id): bool;

    /**
     * @throws \InvalidArgumentException when the effect does not exist
     */
    public function get(string $id): EffectDefinition;
}
