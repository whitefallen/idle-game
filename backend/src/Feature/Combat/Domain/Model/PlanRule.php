<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

final readonly class PlanRule
{
    public function __construct(
        public Condition $condition,
        public string $abilityId,
    ) {
        if ($abilityId === '') {
            throw new InvalidArgumentException('A plan rule must name an ability.');
        }
    }

    /**
     * @return array{condition: list<array<string, string|int>>, abilityId: string}
     */
    public function toArray(): array
    {
        return [
            'condition' => $this->condition->toArray(),
            'abilityId' => $this->abilityId,
        ];
    }
}
