<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Service\HoldingRules;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Assigns a production line to a slot, or clears the slot.
 *
 * Two gates, and both report structurally what is missing rather than a bare
 * refusal — a locked thing that will not say what unlocks it is a bug
 * (docs/progression.md section 5):
 *
 * - the slot must be unlocked at this character's level;
 * - the line must be one this character may produce.
 *
 * Reassignment discards that slot's pending accrual. That rule lives in the
 * aggregate; what lives here is the decision *not* to claim first on the
 * player's behalf. An endpoint that quietly banks pending output as a side
 * effect of an unrelated action makes the claim audit trail unreadable, and the
 * read endpoint already shows what is pending per slot so the choice is
 * informed.
 */
final class AssignProductionSlotHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly HoldingProvisioner $provisioner,
        private readonly MaterialRepository $materials,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId, int $index, ?string $materialId): Holding
    {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $index, $materialId): Holding {
                // Same lock order as the claim: character first. See
                // ClaimHoldingHandler.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $level = $character->level();

                if ($materialId !== null) {
                    $this->assertProducible($materialId, $level);
                }

                $holding = $this->provisioner->forUpdate($characterId);
                $now = $this->clock->now();

                try {
                    $holding->assign($index, $materialId, $level, $now);
                } catch (DomainException $e) {
                    throw ApiException::of(
                        ErrorCode::RequirementNotMet,
                        $e->getMessage(),
                        [
                            'slot' => $index,
                            'slots_unlocked' => HoldingRules::slotsAt($level),
                            'unlocks_at_level' => HoldingRules::slotUnlockLevel($index),
                        ],
                    );
                }

                $this->audit->record(
                    AuditAction::HoldingSlotAssigned,
                    ['slot' => $index, 'materialId' => $materialId],
                    $accountId,
                    $characterId,
                );

                return $holding;
            },
        );
    }

    private function assertProducible(string $materialId, int $level): void
    {
        if (!$this->materials->has($materialId)) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('Unknown material "%s".', $materialId),
            );
        }

        $material = $this->materials->get($materialId);

        // A drop-only material has no production line at any level, so it is
        // reported as a validation failure rather than as a level requirement
        // the player could eventually meet.
        if (!$material->isProducible()) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('"%s" cannot be produced by a Holding.', $materialId),
            );
        }

        if (!$material->isProducibleAt($level)) {
            throw ApiException::of(
                ErrorCode::RequirementNotMet,
                sprintf('That production line requires level %d.', (int) $material->productionUnlockLevel),
                [
                    'required_level' => (int) $material->productionUnlockLevel,
                    'character_level' => $level,
                ],
            );
        }
    }
}
