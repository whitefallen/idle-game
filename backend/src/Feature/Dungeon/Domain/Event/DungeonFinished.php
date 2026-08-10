<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Event;

/**
 * Emitted after a dungeon run ends, cleared or not.
 *
 * Outbox-only, same category as EncounterResolved's outbox half: the run's own
 * rewards are already applied inline in
 * {@see \App\Feature\Dungeon\Application\EnterDungeonHandler}'s transaction.
 * This event exists for the achievements/analytics category future
 * subscribers will occupy. See ADR-0004.
 */
final readonly class DungeonFinished
{
    public const string NAME = 'dungeon.finished';

    public function __construct(
        public string $dungeonRunId,
        public string $characterId,
        public string $dungeonId,
        public bool $cleared,
        public int $stagesCleared,
        public int $totalExperienceAwarded,
        public int $totalGoldAwarded,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dungeonRunId' => $this->dungeonRunId,
            'characterId' => $this->characterId,
            'dungeonId' => $this->dungeonId,
            'cleared' => $this->cleared,
            'stagesCleared' => $this->stagesCleared,
            'totalExperienceAwarded' => $this->totalExperienceAwarded,
            'totalGoldAwarded' => $this->totalGoldAwarded,
        ];
    }
}
