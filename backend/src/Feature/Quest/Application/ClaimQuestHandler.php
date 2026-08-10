<?php

declare(strict_types=1);

namespace App\Feature\Quest\Application;

use App\Feature\Character\Application\CharacterStats;
use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Feature\Inventory\Application\GrantMaterialsHandler;
use App\Feature\Quest\Domain\Entity\QuestRun;
use App\Feature\Quest\Domain\Entity\QuestRunStatus;
use App\Feature\Quest\Domain\Event\QuestCompleted;
use App\Feature\Quest\Domain\Model\QuestDefinition;
use App\Feature\Quest\Domain\Repository\QuestDefinitionRepository;
use App\Feature\Quest\Domain\Repository\QuestRunRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Random\SeedGenerator;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Claims a quest: replays the frozen snapshot through one simulated fight and
 * grants the fixed reward on a win.
 *
 * The character's gear and level at claim time are irrelevant — only the
 * snapshot taken at accept matters, which is the whole anti-exploit point of
 * the expedition model. See docs/adr/0008-quest-snapshot-resolution.md.
 */
final class ClaimQuestHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly QuestDefinitionRepository $definitions,
        private readonly QuestRunRepository $runs,
        private readonly MonsterRepository $monsters,
        private readonly AbilityRepository $abilities,
        private readonly EffectRepository $effects,
        private readonly CombatEngine $engine,
        private readonly SeedGenerator $seeds,
        private readonly GrantMaterialsHandler $grantMaterials,
        private readonly CharacterStats $stats,
        private readonly OutboxRecorder $outbox,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, string $questId): ClaimedQuest
    {
        $definition = $this->definition($questId);

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $definition): ClaimedQuest {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $run = $this->runs->findForCharacterAndQuestForUpdate($characterId, $definition->id);

                if ($run === null || $run->status() !== QuestRunStatus::Active) {
                    throw ApiException::of(ErrorCode::Conflict, 'This quest has not been accepted.');
                }

                $now = $this->clock->now();

                if (!$run->isReadyToClaim($now)) {
                    throw ApiException::of(
                        ErrorCode::Conflict,
                        'This quest is not ready to claim yet.',
                        ['ready_at' => $run->completesAt()->format(DATE_RFC3339)],
                    );
                }

                try {
                    $input = $this->buildInput($run, $definition);
                    $seed = $this->seeds->generate();
                    $log = $this->engine->resolve($input, $seed);
                } catch (InvalidArgumentException) {
                    // The ruleset moved, or the snapshot no longer matches the
                    // shape the engine expects. Nothing was spent to accept
                    // this quest, so this resolves exactly like a lost fight:
                    // no rewards, re-acceptable. See QuestRun::markContentChanged().
                    $run->markContentChanged($now);
                    $this->runs->save($run);

                    $this->audit->record(
                        AuditAction::QuestClaimed,
                        ['questId' => $definition->id, 'outcome' => 'content_changed'],
                        $accountId,
                        $characterId,
                    );

                    return new ClaimedQuest($run, null, $character);
                }

                $rewards = $this->applyRewards($character, $definition, $log->outcome, $now);

                $run->resolve($log, $seed, $rewards, $now);
                $this->runs->save($run);

                $this->outbox->record(QuestCompleted::NAME, (new QuestCompleted(
                    questRunId: $run->id()->toRfc4122(),
                    characterId: $characterId->toRfc4122(),
                    questId: $definition->id,
                    outcome: $log->outcome,
                    experienceAwarded: $rewards['experience'] ?? 0,
                    goldAwarded: $rewards['gold'] ?? 0,
                    materialsAwarded: $rewards['materials'] ?? [],
                ))->toArray());

                $this->audit->record(
                    AuditAction::QuestClaimed,
                    [
                        'questId' => $definition->id,
                        'outcome' => $log->outcome->value,
                        'seed' => (string) $seed,
                        'rewards' => $rewards,
                    ],
                    $accountId,
                    $characterId,
                );

                return new ClaimedQuest($run, $log, $character);
            },
        );
    }

    private function definition(string $questId): QuestDefinition
    {
        try {
            return $this->definitions->get($questId);
        } catch (InvalidArgumentException) {
            throw ApiException::notFound('Quest');
        }
    }

    private function buildInput(QuestRun $run, QuestDefinition $definition): CombatInput
    {
        $snapshot = $run->snapshot();

        if (($snapshot['rulesetVersion'] ?? null) !== CombatEngine::RULESET_VERSION) {
            throw new InvalidArgumentException('Quest snapshot was frozen under a different ruleset.');
        }

        $participants = [$this->deserialize((array) $snapshot['participant'])];

        foreach ($definition->monsterIds as $index => $monsterId) {
            $participants[] = $this->monsters->get($monsterId)->toParticipant(
                sprintf('monster-%02d-%s', $index, $monsterId),
            );
        }

        return new CombatInput(
            $participants,
            $this->abilities->all(),
            $this->effects->all(),
            CombatEngine::RULESET_VERSION,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserialize(array $data): Participant
    {
        /** @var array<string, int> $resistanceRatings */
        $resistanceRatings = (array) $data['resistanceRatings'];

        /** @var list<string> $abilityIds */
        $abilityIds = (array) $data['abilityIds'];

        /** @var list<array<string, mixed>> $battlePlan */
        $battlePlan = (array) $data['battlePlan'];

        return new Participant(
            id: (string) $data['id'],
            definitionId: (string) $data['definitionId'],
            name: (string) $data['name'],
            team: Team::from((string) $data['team']),
            level: (int) $data['level'],
            maxHealth: (int) $data['maxHealth'],
            initiative: (int) $data['initiative'],
            maxFocus: (int) $data['maxFocus'],
            focusPerTurn: (int) $data['focusPerTurn'],
            weaponBaseDamage: (int) $data['weaponBaseDamage'],
            flatDamageBonus: (int) $data['flatDamageBonus'],
            scalingBp: (int) $data['scalingBp'],
            critChanceBp: (int) $data['critChanceBp'],
            critPowerBp: (int) $data['critPowerBp'],
            dodgeChanceBp: (int) $data['dodgeChanceBp'],
            accuracyBp: (int) $data['accuracyBp'],
            armourRating: (int) $data['armourRating'],
            resistanceRatings: $resistanceRatings,
            battlePlan: BattlePlan::fromArray($battlePlan),
            abilityIds: $abilityIds,
        );
    }

    /**
     * @return array{experience?: int, gold?: int, materials?: array<string, int>}
     */
    private function applyRewards(
        \App\Feature\Character\Domain\Entity\Character $character,
        QuestDefinition $definition,
        Outcome $outcome,
        DateTimeImmutable $now,
    ): array {
        if ($outcome !== Outcome::Victory) {
            return [];
        }

        $character->awardExperience($definition->experienceReward, $this->stats->equipmentOf($character->id()), $now);
        $character->awardGold($definition->goldReward, $now);

        $materials = $definition->materialRewards !== []
            ? ($this->grantMaterials)($character->id(), $definition->materialRewards)
            : [];

        return [
            'experience' => $definition->experienceReward,
            'gold' => $definition->goldReward,
            'materials' => $materials,
        ];
    }
}
