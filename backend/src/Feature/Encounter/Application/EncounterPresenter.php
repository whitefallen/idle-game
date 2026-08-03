<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Application;

use App\Feature\Encounter\Domain\Entity\Encounter;

final class EncounterPresenter
{
    /**
     * @param array<string, mixed> $log
     *
     * @return array<string, mixed>
     */
    public function detail(Encounter $encounter, array $log): array
    {
        return [
            'id' => $encounter->id()->toRfc4122(),
            'definition_id' => $encounter->definitionId(),
            'outcome' => $encounter->outcome()->value,
            'rounds' => $encounter->rounds(),
            'rewards' => $encounter->rewards(),
            // A decimal string: the seed is a full 64-bit value and JavaScript's
            // number type cannot hold one without loss.
            'seed' => (string) $encounter->seed(),
            'ruleset_version' => $encounter->rulesetVersion(),
            'created_at' => $encounter->createdAt()->format(DATE_RFC3339),
            'log' => $log,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Encounter $encounter): array
    {
        return [
            'id' => $encounter->id()->toRfc4122(),
            'definition_id' => $encounter->definitionId(),
            'outcome' => $encounter->outcome()->value,
            'rounds' => $encounter->rounds(),
            'rewards' => $encounter->rewards(),
            'created_at' => $encounter->createdAt()->format(DATE_RFC3339),
        ];
    }
}
