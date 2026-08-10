<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use App\Feature\Dungeon\Domain\Model\DungeonDefinition;
use App\Feature\Dungeon\Domain\Repository\DungeonRunRepository;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;

final class DungeonPresenter
{
    public function __construct(
        private readonly MaterialStackRepository $materialStacks,
        private readonly DisciplineRepository $disciplines,
        private readonly DungeonRunRepository $runs,
    ) {
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
     * @param array<string, int> $heldByMaterialId
     *
     * @return array<string, mixed>
     */
    private function summary(DungeonDefinition $definition, Character $character, array $heldByMaterialId): array
    {
        $affordable = true;

        foreach ($definition->cost as $materialId => $amount) {
            if (($heldByMaterialId[$materialId] ?? 0) < $amount) {
                $affordable = false;

                break;
            }
        }

        return [
            'id' => $definition->id,
            'localisation_key' => $definition->localisationKey,
            'required_level' => $definition->requiredLevel,
            'cost' => $definition->cost,
            'repeatable' => $definition->repeatable,
            'stages' => count($definition->encounterIds),
            'completion_bonus' => [
                'xp' => $definition->completionBonusExperience,
                'gold' => $definition->completionBonusGold,
            ],
            'unlocked' => $character->level() >= $definition->requiredLevel,
            'affordable' => $affordable,
            // Only meaningful for a one-time dungeon; always false for a
            // repeatable one, which has no "cleared for good" state.
            'cleared' => !$definition->repeatable && $this->runs->hasCleared($character->id(), $definition->id),
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

        $offered = $run->offeredDisciplineIds();

        return [
            'id' => $run->id()->toRfc4122(),
            'dungeon_id' => $run->dungeonId(),
            'cleared' => $run->cleared(),
            'stages' => $stages,
            'rewards' => $run->rewards(),
            'created_at' => $run->createdAt()->format(DATE_RFC3339),
            // Ability id travels alongside each offered discipline id so the
            // client can render a name without a second lookup — the same
            // reason CharacterPresenter::disciplines() includes it.
            'offered_disciplines' => $offered === null ? null : array_map(
                fn (string $id): array => ['id' => $id, 'ability_id' => $this->disciplines->get($id)->abilityId],
                $offered,
            ),
            'picked_discipline_id' => $run->pickedDisciplineId(),
        ];
    }
}
