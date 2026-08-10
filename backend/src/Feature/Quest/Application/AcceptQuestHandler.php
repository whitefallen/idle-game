<?php

declare(strict_types=1);

namespace App\Feature\Quest\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Encounter\Application\CharacterParticipantFactory;
use App\Feature\Quest\Domain\Entity\QuestRun;
use App\Feature\Quest\Domain\Entity\QuestRunStatus;
use App\Feature\Quest\Domain\Model\QuestDefinition;
use App\Feature\Quest\Domain\Repository\QuestDefinitionRepository;
use App\Feature\Quest\Domain\Repository\QuestRunRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Uid\IdentifierGenerator;
use DateInterval;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Accepts a quest: freezes the character as a combat snapshot and starts its
 * timer. Nothing else happens — no Vigor is spent, because the cost of a
 * quest is the wait, not a resource. See
 * docs/adr/0008-quest-snapshot-resolution.md.
 */
final class AcceptQuestHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly QuestDefinitionRepository $definitions,
        private readonly QuestRunRepository $runs,
        private readonly CharacterParticipantFactory $participants,
        private readonly IdentifierGenerator $identifiers,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, string $questId): QuestRun
    {
        $definition = $this->definition($questId);

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $definition): QuestRun {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $this->assertEligible($character, $definition);

                $now = $this->clock->now();
                $completesAt = $now->add(new DateInterval(sprintf('PT%dS', $definition->durationSeconds)));
                $snapshot = $this->snapshot($this->participants->create($character));

                $existing = $this->runs->findForCharacterAndQuestForUpdate($characterId, $definition->id);

                if ($existing !== null) {
                    if ($existing->status() !== QuestRunStatus::Failed) {
                        throw ApiException::of(
                            ErrorCode::Conflict,
                            $existing->status() === QuestRunStatus::Claimed
                                ? 'This quest has already been completed.'
                                : 'This quest is already active.',
                        );
                    }

                    $existing->reaccept($snapshot, $now, $completesAt);
                    $this->runs->save($existing);
                    $run = $existing;
                } else {
                    $run = new QuestRun(
                        $this->identifiers->generate(),
                        $characterId,
                        $definition->id,
                        $snapshot,
                        $now,
                        $completesAt,
                    );
                    $this->runs->save($run);
                }

                $this->audit->record(
                    AuditAction::QuestAccepted,
                    ['questId' => $definition->id, 'completesAt' => $completesAt->format(DATE_RFC3339)],
                    $accountId,
                    $characterId,
                );

                return $run;
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

    private function assertEligible(Character $character, QuestDefinition $definition): void
    {
        if ($character->level() < $definition->requiredLevel) {
            throw ApiException::of(
                ErrorCode::RequirementNotMet,
                sprintf('This quest requires level %d.', $definition->requiredLevel),
                ['required_level' => $definition->requiredLevel, 'character_level' => $character->level()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Participant $p): array
    {
        return [
            'rulesetVersion' => CombatEngine::RULESET_VERSION,
            'participant' => [
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
        ];
    }
}
