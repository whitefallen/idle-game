<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Event;

use App\Feature\Combat\Domain\Model\Outcome;

/**
 * Emitted after an encounter is resolved and its rewards applied.
 *
 * Carries ids and primitives only, never entities: an entity in an event is a
 * reference to mutable state that may have changed by the time an asynchronous
 * handler reads it.
 *
 * Subscribers to this event handle the deferred half of the fan-out — quest
 * progress, achievements, the guild feed, analytics. Experience, gold and
 * Vigor are applied synchronously inside the resolving transaction, because a
 * player would notice and report those missing. See ADR-0004.
 */
final readonly class EncounterResolved
{
    public const string NAME = 'encounter.resolved';

    /**
     * @param list<string> $defeatedMonsterIds Content ids, repeated when the
     *                                         same monster appeared more than once.
     */
    public function __construct(
        public string $encounterId,
        public string $characterId,
        public string $definitionId,
        public Outcome $outcome,
        public int $rounds,
        public int $experienceAwarded,
        public int $goldAwarded,
        public int $levelsGained,
        public array $defeatedMonsterIds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'encounterId' => $this->encounterId,
            'characterId' => $this->characterId,
            'definitionId' => $this->definitionId,
            'outcome' => $this->outcome->value,
            'rounds' => $this->rounds,
            'experienceAwarded' => $this->experienceAwarded,
            'goldAwarded' => $this->goldAwarded,
            'levelsGained' => $this->levelsGained,
            'defeatedMonsterIds' => $this->defeatedMonsterIds,
        ];
    }
}
