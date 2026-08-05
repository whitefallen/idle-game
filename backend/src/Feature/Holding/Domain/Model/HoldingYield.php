<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Model;

/**
 * What a claim produced.
 *
 * Also what a *projection* produces: the read endpoint runs the same
 * calculation against the same state without committing it, so what a player is
 * shown as pending and what they are later granted come from one code path
 * rather than from two that agree until one is changed.
 */
final readonly class HoldingYield
{
    /**
     * @param array<string, int> $materials Keyed by material id. Only lines
     *                                      that produced at least one whole
     *                                      unit appear.
     * @param int                $elapsedSeconds Time the claim covered, after
     *                                      the cap clamp — the figure the audit
     *                                      record needs (docs/idle.md rule T6).
     */
    public function __construct(
        public array $materials,
        public int $gold,
        public int $elapsedSeconds,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->gold === 0 && $this->materials === [];
    }

    public function totalMaterials(): int
    {
        return array_sum($this->materials);
    }
}
