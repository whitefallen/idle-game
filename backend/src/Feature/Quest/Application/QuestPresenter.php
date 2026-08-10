<?php

declare(strict_types=1);

namespace App\Feature\Quest\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Quest\Domain\Entity\QuestRun;
use App\Feature\Quest\Domain\Entity\QuestRunStatus;
use App\Feature\Quest\Domain\Model\QuestDefinition;
use DateTimeImmutable;

final class QuestPresenter
{
    /**
     * @param array<string, QuestDefinition> $definitions
     * @param array<string, QuestRun>        $runs Keyed by quest id.
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $definitions, array $runs, Character $character, DateTimeImmutable $now): array
    {
        return array_values(array_map(
            fn (QuestDefinition $definition): array => $this->summary($definition, $runs[$definition->id] ?? null, $character, $now),
            $definitions,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(QuestDefinition $definition, ?QuestRun $run, Character $character, DateTimeImmutable $now): array
    {
        return [
            'id' => $definition->id,
            'localisation_key' => $definition->localisationKey,
            'required_level' => $definition->requiredLevel,
            'duration_seconds' => $definition->durationSeconds,
            'monsters' => $definition->monsterIds,
            'rewards' => [
                'xp' => $definition->experienceReward,
                'gold' => $definition->goldReward,
                'materials' => $definition->materialRewards,
            ],
            'unlocked' => $character->level() >= $definition->requiredLevel,
            // 'available' when never attempted or the last attempt failed
            // (nothing was spent, so accepting again is always allowed then).
            'status' => $run?->status()->value ?? 'available',
            'completes_at' => $run?->status() === QuestRunStatus::Active
                ? $run->completesAt()->format(DATE_RFC3339)
                : null,
            'ready_to_claim' => $run !== null && $run->isReadyToClaim($now),
        ];
    }

    /**
     * @param array<string, mixed>|null $log
     *
     * @return array<string, mixed>
     */
    public function claimResult(ClaimedQuest $claimed, ?array $log): array
    {
        $run = $claimed->run;

        return [
            'quest_id' => $run->questId(),
            'status' => $run->status()->value,
            'outcome' => $run->outcome()?->value,
            'rewards' => $run->rewards() ?? ['experience' => 0, 'gold' => 0, 'materials' => []],
            'resolved_at' => $run->resolvedAt()?->format(DATE_RFC3339),
            'log' => $log,
        ];
    }
}
