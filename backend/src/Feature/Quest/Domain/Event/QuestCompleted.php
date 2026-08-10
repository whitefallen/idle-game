<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Event;

use App\Feature\Combat\Domain\Model\Outcome;

/**
 * Emitted after a quest claim resolves, win or lose.
 *
 * Outbox-only: nothing in the request path needs to react to this
 * synchronously — the quest's own rewards are already applied inline in
 * {@see \App\Feature\Quest\Application\ClaimQuestHandler}'s transaction,
 * exactly like Encounter applies XP/gold before emitting EncounterResolved.
 * This event exists for the achievements/analytics category future
 * subscribers will occupy. See ADR-0004.
 */
final readonly class QuestCompleted
{
    public const string NAME = 'quest.completed';

    public function __construct(
        public string $questRunId,
        public string $characterId,
        public string $questId,
        public Outcome $outcome,
        public int $experienceAwarded,
        public int $goldAwarded,
        /** @var array<string, int> */
        public array $materialsAwarded,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'questRunId' => $this->questRunId,
            'characterId' => $this->characterId,
            'questId' => $this->questId,
            'outcome' => $this->outcome->value,
            'experienceAwarded' => $this->experienceAwarded,
            'goldAwarded' => $this->goldAwarded,
            'materialsAwarded' => $this->materialsAwarded,
        ];
    }
}
