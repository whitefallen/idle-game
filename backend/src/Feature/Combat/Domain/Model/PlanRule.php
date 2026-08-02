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
     * Accepts both the persisted shape (`abilityId`) and the content-authoring
     * shape (`ability`), which reads better in YAML.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $abilityId = $data['abilityId'] ?? $data['ability'] ?? null;

        if (!is_string($abilityId) || $abilityId === '') {
            throw new InvalidArgumentException('A plan rule must name an ability.');
        }

        $condition = $data['condition'] ?? null;

        if (!is_array($condition)) {
            throw new InvalidArgumentException(
                sprintf('Plan rule for "%s" is missing its condition.', $abilityId),
            );
        }

        /** @var list<array<string, mixed>> $condition */
        return new self(Condition::fromArray(array_values($condition)), $abilityId);
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
