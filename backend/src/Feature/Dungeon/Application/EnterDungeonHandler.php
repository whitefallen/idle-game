<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Application\CharacterStats;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Model\DisciplineSource;
use App\Feature\Character\Domain\Repository\CharacterDisciplineRepository;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use App\Feature\Dungeon\Domain\Event\DungeonFinished;
use App\Feature\Dungeon\Domain\Model\DungeonDefinition;
use App\Feature\Dungeon\Domain\Repository\DungeonDefinitionRepository;
use App\Feature\Dungeon\Domain\Repository\DungeonRunRepository;
use App\Feature\Encounter\Application\CharacterParticipantFactory;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Feature\Encounter\Domain\Service\RewardRules;
use App\Feature\Inventory\Application\ResolveDropsHandler;
use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Random\SeedGenerator;
use App\Platform\Uid\IdentifierGenerator;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Enters a dungeon: consumes its key and resolves every stage live, in one
 * request, stopping at the first non-Victory stage.
 *
 * Unlike Quest, nothing here is snapshotted — the character's current stats
 * feed every stage, and a level gained mid-run carries into the next stage,
 * exactly as it would across two standalone encounters played back to back.
 */
final class EnterDungeonHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly DungeonDefinitionRepository $definitions,
        private readonly EncounterDefinitionRepository $encounterDefinitions,
        private readonly MonsterRepository $monsters,
        private readonly AbilityRepository $abilities,
        private readonly EffectRepository $effects,
        private readonly CombatEngine $engine,
        private readonly SeedGenerator $seeds,
        private readonly CharacterParticipantFactory $participants,
        private readonly CharacterStats $stats,
        private readonly MaterialStackRepository $materialStacks,
        private readonly ResolveDropsHandler $drops,
        private readonly DisciplineRepository $disciplines,
        private readonly CharacterDisciplineRepository $ownedDisciplines,
        private readonly DungeonRunRepository $runs,
        private readonly IdentifierGenerator $identifiers,
        private readonly OutboxRecorder $outbox,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, string $dungeonId): EnteredDungeon
    {
        $definition = $this->definition($dungeonId);

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $definition): EnteredDungeon {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $this->assertEligible($character, $definition);

                $now = $this->clock->now();
                $this->consumeCost($characterId, $definition, $now);

                $this->audit->record(
                    AuditAction::DungeonEntered,
                    ['dungeonId' => $definition->id],
                    $accountId,
                    $characterId,
                );

                [$stages, $logs, $cleared, $lastSeed] = $this->runStages($character, $definition, $now);
                $rewards = $this->applyCompletionRewards($character, $definition, $cleared, $lastSeed, $now);

                $offeredDisciplineIds = !$definition->repeatable && $cleared
                    ? $this->offerDisciplines($character)
                    : null;

                $run = new DungeonRun(
                    $this->identifiers->generate(),
                    $characterId,
                    $definition->id,
                    $stages,
                    $logs,
                    $cleared,
                    $rewards,
                    $now,
                    $offeredDisciplineIds,
                );

                $this->runs->save($run);

                $stageExperience = array_sum(array_column($stages, 'experience'));
                $stageGold = array_sum(array_column($stages, 'gold'));

                $this->outbox->record(DungeonFinished::NAME, (new DungeonFinished(
                    dungeonRunId: $run->id()->toRfc4122(),
                    characterId: $characterId->toRfc4122(),
                    dungeonId: $definition->id,
                    cleared: $cleared,
                    stagesCleared: count($stages) - ($cleared ? 0 : 1),
                    totalExperienceAwarded: $stageExperience + ($rewards['experience'] ?? 0),
                    totalGoldAwarded: $stageGold + ($rewards['gold'] ?? 0),
                ))->toArray());

                $this->audit->record(
                    AuditAction::DungeonCompleted,
                    ['dungeonId' => $definition->id, 'cleared' => $cleared, 'stages' => $stages, 'rewards' => $rewards],
                    $accountId,
                    $characterId,
                );

                return new EnteredDungeon($run, $character);
            },
        );
    }

    private function definition(string $dungeonId): DungeonDefinition
    {
        try {
            return $this->definitions->get($dungeonId);
        } catch (InvalidArgumentException) {
            throw ApiException::notFound('Dungeon');
        }
    }

    private function assertEligible(Character $character, DungeonDefinition $definition): void
    {
        if ($character->level() < $definition->requiredLevel) {
            throw ApiException::of(
                ErrorCode::RequirementNotMet,
                sprintf('This dungeon requires level %d.', $definition->requiredLevel),
                ['required_level' => $definition->requiredLevel, 'character_level' => $character->level()],
            );
        }

        // A failed attempt does not lock the player out — only a clear does.
        // See docs/dungeons.md section 2.
        if (!$definition->repeatable && $this->runs->hasCleared($character->id(), $definition->id)) {
            throw ApiException::of(
                ErrorCode::Conflict,
                'This dungeon has already been cleared and cannot be entered again.',
            );
        }
    }

    /**
     * Locks every material in the cost, in sorted-by-id order — the same
     * deadlock-avoidance reasoning GrantMaterialsHandler documents applies
     * here in reverse: two dungeons with an overlapping cost must take their
     * row locks in the same order. Checks the whole cost is affordable
     * before consuming any of it, so a multi-material cost never partially
     * spends on a request that was always going to fail.
     */
    private function consumeCost(Uuid $characterId, DungeonDefinition $definition, \DateTimeImmutable $now): void
    {
        $cost = $definition->cost;
        ksort($cost, SORT_STRING);

        /** @var array<string, MaterialStack> $stacks */
        $stacks = [];

        foreach ($cost as $materialId => $amount) {
            $stack = $this->materialStacks->findForUpdate($characterId, $materialId);

            if ($stack === null || $stack->quantity() < $amount) {
                throw ApiException::of(
                    ErrorCode::InsufficientMaterial,
                    'This dungeon requires materials you do not have enough of.',
                    ['material_id' => $materialId, 'required' => $amount, 'available' => $stack?->quantity() ?? 0],
                );
            }

            $stacks[$materialId] = $stack;
        }

        foreach ($stacks as $materialId => $stack) {
            $stack->consume($cost[$materialId], $now);
            $this->materialStacks->save($stack);
        }
    }

    /**
     * @return array{
     *     0: list<array{encounterId: string, seed: string, outcome: string, rounds: int, experience: int, gold: int}>,
     *     1: list<array<string, mixed>>,
     *     2: bool,
     *     3: int,
     * }
     */
    private function runStages(Character $character, DungeonDefinition $definition, \DateTimeImmutable $now): array
    {
        $stages = [];
        $logs = [];
        $cleared = true;
        $seed = 0;

        foreach ($definition->encounterIds as $encounterId) {
            $encounter = $this->encounterDefinitions->get($encounterId);

            $participants = [$this->participants->create($character)];

            foreach ($encounter->monsterIds as $index => $monsterId) {
                $participants[] = $this->monsters->get($monsterId)->toParticipant(
                    sprintf('monster-%02d-%s', $index, $monsterId),
                );
            }

            $input = new CombatInput($participants, $this->abilities->all(), $this->effects->all(), CombatEngine::RULESET_VERSION);
            $seed = $this->seeds->generate();
            $log = $this->engine->resolve($input, $seed);

            $stageExperience = 0;
            $stageGold = 0;

            if ($log->outcome === Outcome::Victory) {
                $stageExperience = RewardRules::experience($encounter, $character->level());
                $stageGold = RewardRules::gold($encounter, $seed);

                $character->awardExperience($stageExperience, $this->stats->equipmentOf($character->id()), $now);
                $character->awardGold($stageGold, $now);
            }

            $stages[] = [
                'encounterId' => $encounterId,
                'seed' => (string) $seed,
                'outcome' => $log->outcome->value,
                'rounds' => $log->rounds,
                'experience' => $stageExperience,
                'gold' => $stageGold,
            ];
            $logs[] = $log->toArray();

            if ($log->outcome !== Outcome::Victory) {
                // Rewards already granted for cleared stages stand — no
                // rollback. Consistent with server authority and avoids an
                // all-or-nothing design nobody asked for.
                $cleared = false;

                break;
            }
        }

        return [$stages, $logs, $cleared, $seed];
    }

    /**
     * @return array{experience: int, gold: int, items: int, materials: array<string, int>}
     */
    private function applyCompletionRewards(
        Character $character,
        DungeonDefinition $definition,
        bool $cleared,
        int $lastSeed,
        \DateTimeImmutable $now,
    ): array {
        if (!$cleared) {
            return ['experience' => 0, 'gold' => 0, 'items' => 0, 'materials' => []];
        }

        $character->awardExperience(
            $definition->completionBonusExperience,
            $this->stats->equipmentOf($character->id()),
            $now,
        );
        $character->awardGold($definition->completionBonusGold, $now);

        $loot = $definition->dropTableId !== null
            ? ($this->drops)($character->id(), $definition->dropTableId, $character->attributes()->luck, $lastSeed)
            : ['items' => [], 'materials' => []];

        return [
            'experience' => $definition->completionBonusExperience,
            'gold' => $definition->completionBonusGold,
            'items' => count($loot['items']),
            'materials' => $loot['materials'],
        ];
    }

    /**
     * `min(3, remaining)` disciplines from this character's dungeon-tier
     * pool — see docs/dungeons.md section 3. An empty list is valid: it
     * means this character's pool is already fully collected, and the clear
     * still stands on its other rewards alone.
     *
     * @return list<string>
     */
    private function offerDisciplines(Character $character): array
    {
        $owned = $this->ownedDisciplines->idsForCharacter($character->id());

        $remaining = [];

        foreach ($this->disciplines->all() as $discipline) {
            if ($discipline->source === DisciplineSource::Dungeon && !in_array($discipline->id, $owned, true)) {
                $remaining[] = $discipline->id;
            }
        }

        if ($remaining === []) {
            return [];
        }

        shuffle($remaining);

        return array_slice($remaining, 0, 3);
    }
}
