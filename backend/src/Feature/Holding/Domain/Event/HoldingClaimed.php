<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Event;

/**
 * Emitted after a Holding claim has been granted.
 *
 * The materials and the gold are applied synchronously inside the claiming
 * transaction — a player would immediately notice and report those missing.
 * This event carries the deferred half: quest progress ("claim your Holding
 * three times"), achievements, and the economy analytics that make passive
 * versus active gold a monitored ratio rather than an assumption
 * (docs/economy.md section 2).
 *
 * Ids and primitives only, never entities. See ADR-0004.
 */
final readonly class HoldingClaimed
{
    public const string NAME = 'holding.claimed';

    /**
     * @param array<string, int> $materials Keyed by material content id.
     */
    public function __construct(
        public string $characterId,
        public array $materials,
        public int $goldAwarded,
        public int $elapsedSeconds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'characterId' => $this->characterId,
            'materials' => $this->materials,
            'goldAwarded' => $this->goldAwarded,
            'elapsedSeconds' => $this->elapsedSeconds,
        ];
    }
}
