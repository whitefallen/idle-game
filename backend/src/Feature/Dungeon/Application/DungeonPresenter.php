<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use App\Feature\Dungeon\Domain\Model\DungeonDefinition;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;

final class DungeonPresenter
{
    public function __construct(private readonly MaterialStackRepository $materialStacks)
    {
    }

    /**
     * @param array<string, DungeonDefinition> $definitions
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $definitions, Character $character): array
    {
        // One unlocked read for every stack the character holds, rather than
        // one findForUpdate() per dungeon: this is a listing, not a
        // read-modify-write, and findForUpdate() exists precisely for the
        // latter — taking a write lock here would serialise every concurrent
        // read of this endpoint against every material-consuming write.
        $held = [];

        foreach ($this->materialStacks->findByCharacter($character->id()) as $stack) {
            $held[$stack->materialId()] = $stack->quantity();
        }

        return array_values(array_map(
            fn (DungeonDefinition $definition): array => $this->summary($definition, $character, $held),
            $definitions,
        ));
    }

    /**
     * @param array<string, int> $keysHeldByMaterialId
     *
     * @return array<string, mixed>
     */
    private function summary(DungeonDefinition $definition, Character $character, array $keysHeldByMaterialId): array
    {
        $keysHeld = $keysHeldByMaterialId[$definition->keyMaterialId] ?? 0;

        return [
            'id' => $definition->id,
            'localisation_key' => $definition->localisationKey,
            'required_level' => $definition->requiredLevel,
            'key_material_id' => $definition->keyMaterialId,
            'stages' => count($definition->encounterIds),
            'completion_bonus' => [
                'xp' => $definition->completionBonusExperience,
                'gold' => $definition->completionBonusGold,
            ],
            'unlocked' => $character->level() >= $definition->requiredLevel,
            'keys_held' => $keysHeld,
        ];
    }

    /**
     * @param list<array<string, mixed>> $logs Index-aligned with the run's stages.
     *
     * @return array<string, mixed>
     */
    public function detail(DungeonRun $run, array $logs): array
    {
        $stages = array_map(
            static fn (array $stage, int $index): array => [...$stage, 'log' => $logs[$index] ?? null],
            $run->stages(),
            array_keys($run->stages()),
        );

        return [
            'id' => $run->id()->toRfc4122(),
            'dungeon_id' => $run->dungeonId(),
            'cleared' => $run->cleared(),
            'stages' => $stages,
            'rewards' => $run->rewards(),
            'created_at' => $run->createdAt()->format(DATE_RFC3339),
        ];
    }
}
