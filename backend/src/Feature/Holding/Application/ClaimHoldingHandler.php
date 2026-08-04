<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Holding\Domain\Event\HoldingClaimed;
use App\Feature\Inventory\Application\GrantMaterialsHandler;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Claims everything a Holding has produced since the last claim.
 *
 * The whole claim commits as one transaction: the anchors move, the materials
 * land in the stash and the gold lands on the character together, or none of it
 * happens. A claim that advanced the anchors but failed to grant the output
 * would destroy real player time with no way to reconstruct it.
 *
 * Anti-exploit rules, all of which live on this path (docs/idle.md section 5):
 *
 * - **T1** — the server owns the clock. No timestamp is read from the request.
 * - **T2** — the Holding row is locked for the whole transaction.
 * - **T3** — the endpoint takes an idempotency key, handled in the controller.
 * - **T4** — elapsed time is clamped at both ends inside the accrual maths.
 * - **T5** — nothing is incremented on a schedule; output is derived on claim.
 * - **T6** — every claim is audited with amount, elapsed time and balance.
 *
 * Claiming an empty Holding is not an error. It succeeds and yields nothing,
 * which is what a double-tap, an over-eager client and an honest player who
 * claimed a minute ago all deserve — an error would be indistinguishable from
 * something actually being wrong.
 */
final class ClaimHoldingHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly HoldingProvisioner $provisioner,
        private readonly ProductionRates $rates,
        private readonly GrantMaterialsHandler $grantMaterials,
        private readonly OutboxRecorder $outbox,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId): ClaimedHolding
    {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId): ClaimedHolding {
                // The character is locked first, and always first. It is needed
                // anyway to award the gold, and taking it before the Holding
                // gives every writer in the system one lock order — an
                // encounter and a claim racing on the same character queue
                // instead of deadlocking. It also serialises the lazy creation
                // of the Holding itself. See HoldingProvisioner.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $holding = $this->provisioner->forUpdate($characterId);

                $now = $this->clock->now();
                $claimed = $holding->claim($character->level(), $this->rates->perHour(), $now);

                $granted = ($this->grantMaterials)($characterId, $claimed->materials);
                $character->awardGold($claimed->gold, $now);

                $this->outbox->record(HoldingClaimed::NAME, (new HoldingClaimed(
                    characterId: $characterId->toRfc4122(),
                    materials: $granted,
                    goldAwarded: $claimed->gold,
                    elapsedSeconds: $claimed->elapsedSeconds,
                ))->toArray());

                // Amount, elapsed time and resulting balance, which is exactly
                // what rule T6 asks for. The elapsed figure is the one that
                // makes time exploits visible in aggregate: it can never
                // legitimately exceed the accrual cap.
                $this->audit->record(
                    AuditAction::HoldingClaimed,
                    [
                        'elapsedSeconds' => $claimed->elapsedSeconds,
                        'materials' => $granted,
                        'gold' => ['granted' => $claimed->gold, 'balance' => $character->gold()],
                    ],
                    $accountId,
                    $characterId,
                );

                return new ClaimedHolding($holding, $character, $claimed);
            },
        );
    }
}
