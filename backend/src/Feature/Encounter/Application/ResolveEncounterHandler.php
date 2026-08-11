<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Encounter\Domain\Entity\Encounter;
use App\Feature\Encounter\Domain\Event\EncounterResolved;
use App\Feature\Encounter\Domain\Model\EncounterDefinition;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use App\Feature\Encounter\Domain\Repository\EncounterRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Feature\Encounter\Domain\Service\RewardRules;
use App\Feature\Character\Application\CharacterStats;
use App\Feature\Inventory\Application\ResolveDropsHandler;
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
 * Resolves an encounter and applies its consequences.
 *
 * Everything a player would notice missing — Vigor spent, experience, gold,
 * the stored log — commits in one transaction. Everything else, which for now
 * means quest progress, achievements and analytics, is deferred through the
 * outbox. See ADR-0004.
 *
 * The server is authoritative throughout: the client names an encounter and
 * nothing else. It does not supply the seed, the participants, the rewards, or
 * its own character's stats.
 */
final class ResolveEncounterHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly EncounterDefinitionRepository $definitions,
        private readonly MonsterRepository $monsters,
        private readonly AbilityRepository $abilities,
        private readonly EffectRepository $effects,
        private readonly EncounterRepository $encounters,
        private readonly CharacterParticipantFactory $participants,
        private readonly CombatEngine $engine,
        private readonly SeedGenerator $seeds,
        private readonly IdentifierGenerator $identifiers,
        private readonly OutboxRecorder $outbox,
        private readonly ResolveDropsHandler $drops,
        private readonly CharacterStats $stats,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, string $definitionId): ResolvedEncounter
    {
        $definition = $this->definition($definitionId);

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $definition): ResolvedEncounter {
                // Locked for update. Without it, two concurrent requests both
                // read the same Vigor balance and both fight — the classic
                // double-tap that turns one Vigor cost into two encounters.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $now = $this->clock->now();
                $character->regenerateVigor($now);

                $this->assertEligible($character, $definition, $now);

                $character->spendVigor($definition->vigorCost, $now);

                $seed = $this->seeds->generate();
                $input = $this->buildInput($character, $definition);
                $log = $this->engine->resolve($input, $seed);

                $rewards = $this->applyRewards($character, $definition, $log, $seed, $now);

                // Loot only on a win, and inside the same transaction: a
                // rolled-back encounter must grant nothing.
                $loot = $log->outcome === Outcome::Victory
                    ? ($this->drops)(
                        $character->id(),
                        $definition->dropTableId,
                        $character->attributes()->luck,
                        $seed,
                    )
                    : ['items' => [], 'materials' => []];

                $rewards['items'] = count($loot['items']);
                $rewards['materials'] = $loot['materials'];

                $encounter = new Encounter(
                    $this->identifiers->generate(),
                    $character->id(),
                    $definition->id,
                    $log,
                    $this->snapshot($input),
                    $rewards,
                    $now,
                );

                $this->encounters->save($encounter);

                $event = new EncounterResolved(
                    encounterId: $encounter->id()->toRfc4122(),
                    characterId: $character->id()->toRfc4122(),
                    definitionId: $definition->id,
                    outcome: $log->outcome,
                    rounds: $log->rounds,
                    experienceAwarded: $rewards['experience'],
                    goldAwarded: $rewards['gold'],
                    levelsGained: $rewards['levelsGained'],
                    defeatedMonsterIds: $log->outcome === Outcome::Victory ? $definition->monsterIds : [],
                );

                $this->outbox->record(EncounterResolved::NAME, $event->toArray());

                // One record carrying every mutation the encounter caused,
                // rather than one row per mutation.
                //
                // docs/economy.md section 5 requires source, amount and
                // resulting balance for every currency change, and this
                // satisfies that. A row per mutation would satisfy it too, at
                // four rows per encounter — roughly a hundred per player per
                // day at the Vigor cap — for no extra investigative power,
                // since the mutations of one fight are only ever read together.
                $this->audit->record(
                    AuditAction::EncounterResolved,
                    [
                        'encounterId' => $encounter->id()->toRfc4122(),
                        'definitionId' => $definition->id,
                        'outcome' => $log->outcome->value,
                        'seed' => (string) $seed,
                        'gold' => ['granted' => $rewards['gold'], 'balance' => $character->gold()],
                        'experience' => [
                            'granted' => $rewards['experience'],
                            'balance' => $character->experience(),
                            'level' => $character->level(),
                            'levelsGained' => $rewards['levelsGained'],
                        ],
                        'vigor' => [
                            'spent' => $definition->vigorCost,
                            'refunded' => $rewards['vigorRefunded'],
                            'balance' => $character->vigor(),
                        ],
                    ],
                    $accountId,
                    $character->id(),
                );

                return new ResolvedEncounter($encounter, $log, $character, $rewards);
            },
        );
    }

    private function definition(string $definitionId): EncounterDefinition
    {
        try {
            return $this->definitions->get($definitionId);
        } catch (InvalidArgumentException) {
            throw ApiException::notFound('Encounter');
        }
    }

    private function assertEligible(
        Character $character,
        EncounterDefinition $definition,
        \DateTimeImmutable $now,
    ): void {
        if ($character->level() < $definition->requiredLevel) {
            // Requirements are returned structurally so the UI can say exactly
            // what is missing rather than "you cannot do this yet".
            throw ApiException::of(
                ErrorCode::RequirementNotMet,
                sprintf('This encounter requires level %d.', $definition->requiredLevel),
                ['required_level' => $definition->requiredLevel, 'character_level' => $character->level()],
            );
        }

        // Checked before affordability: being busy is the more immediate
        // reason, and telling a player they lack Vigor when the real answer is
        // "in two seconds" would send them looking at the wrong number.
        //
        // Two overlapping requests cannot both pass this. The character row is
        // held under a write lock for the whole transaction, so the second
        // request reads the state the first committed, sees the gate it just
        // opened, and is rejected here.
        if (!$character->canStartVigorActivity($now)) {
            $remaining = $character->secondsUntilVigorActivity($now);

            throw ApiException::of(
                ErrorCode::ActivityInProgress,
                'Another activity is still resolving.',
                [
                    'seconds_remaining' => $remaining,
                    'ready_at' => $character->vigorActivityReadyAt($now)->format(DATE_RFC3339),
                ],
            );
        }

        if (!$character->hasVigor($definition->vigorCost)) {
            throw ApiException::of(
                ErrorCode::InsufficientVigor,
                'Not enough Vigor to start this encounter.',
                ['required' => $definition->vigorCost, 'available' => $character->vigor()],
            );
        }
    }

    private function buildInput(Character $character, EncounterDefinition $definition): CombatInput
    {
        $participants = [$this->participants->create($character)];

        foreach ($definition->monsterIds as $index => $monsterId) {
            // Index-prefixed so the same monster can appear several times with
            // distinct, stable identities.
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
     * @return array{experience: int, gold: int, levelsGained: int, vigorRefunded: int, items?: int, materials?: array<string, int>}
     */
    private function applyRewards(
        Character $character,
        EncounterDefinition $definition,
        CombatLog $log,
        int $seed,
        \DateTimeImmutable $now,
    ): array {
        $rewards = ['experience' => 0, 'gold' => 0, 'levelsGained' => 0, 'vigorRefunded' => 0];

        $vigorRefund = RewardRules::vigorRefund($log->outcome, $definition->vigorCost);

        if ($vigorRefund > 0) {
            $character->refundVigor($vigorRefund, $now);
            $rewards['vigorRefunded'] = $vigorRefund;
        }

        if (!RewardRules::isPayable($log->outcome)) {
            return $rewards;
        }

        $experience = RewardRules::experience($definition, $character->level());
        $gold = RewardRules::gold($definition, $seed);

        $rewards['levelsGained'] = $character->awardExperience(
            $experience,
            $this->stats->equipmentOf($character->id()),
            $now,
        );
        $character->awardGold($gold, $now);

        $rewards['experience'] = $experience;
        $rewards['gold'] = $gold;

        return $rewards;
    }

    /**
     * The participant snapshot persisted alongside the log.
     *
     * @return array<string, mixed>
     */
    private function snapshot(CombatInput $input): array
    {
        return [
            'rulesetVersion' => $input->rulesetVersion,
            'participants' => array_map(
                static fn (Participant $p): array => [
                    'id' => $p->id,
                    'definitionId' => $p->definitionId,
                    'name' => $p->name,
                    'team' => $p->team->value,
                    'level' => $p->level,
                    'maxHealth' => $p->maxHealth,
                    'initiative' => $p->initiative,
                    'maxFocus' => $p->maxFocus,
                    'focusPerTurn' => $p->focusPerTurn,
                    'weaponBaseDamage' => $p->weaponBaseDamage,
                    'flatDamageBonus' => $p->flatDamageBonus,
                    'scalingBp' => $p->scalingBp,
                    'critChanceBp' => $p->critChanceBp,
                    'critPowerBp' => $p->critPowerBp,
                    'dodgeChanceBp' => $p->dodgeChanceBp,
                    'accuracyBp' => $p->accuracyBp,
                    'armourRating' => $p->armourRating,
                    'resistanceRatings' => $p->resistanceRatings,
                    'abilityIds' => $p->abilityIds,
                    'battlePlan' => $p->battlePlan->toArray(),
                ],
                $input->participants,
            ),
        ];
    }
}
