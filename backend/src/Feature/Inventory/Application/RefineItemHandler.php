<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Event\ItemRefined;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use App\Feature\Inventory\Domain\Service\EquipmentCalculator;
use App\Feature\Inventory\Domain\Service\RefinementRules;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Outbox\OutboxRecorder;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Advances an item's refinement by exactly one level.
 *
 * The whole thing commits as one transaction: the gold, the material and the
 * refinement level move together, or none of it does — the same reasoning
 * ClaimHoldingHandler documents for the Holding applies here, since gold and
 * material are both real player resources.
 *
 * The material is one the caller chooses, not one the server infers. A tier
 * can carry more than one material (docs/idle.md pairs a drop-only material
 * with a producible one at the same tier), and the player — not this handler
 * — decides which stash to spend down. What the handler enforces is only that
 * the chosen material's tier actually matches the item, the same division of
 * responsibility AssignProductionSlotHandler uses for production lines.
 */
final class RefineItemHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly MaterialRepository $materials,
        private readonly MaterialStackRepository $materialStacks,
        private readonly AuditLogger $audit,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $itemId, string $materialId): ItemInstance
    {
        return $this->transactions->transactional(
            function () use ($accountId, $itemId, $materialId): ItemInstance {
                $item = $this->items->findById($itemId);

                if ($item === null) {
                    throw ApiException::notFound('Item');
                }

                // Locked first, and always first — the same lock order every
                // other writer in this system uses, so a refine and an equip
                // or a Holding claim racing on the same character queue rather
                // than deadlock.
                $character = $this->characters->findByIdForUpdate($item->characterId());

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Item');
                }

                if ($item->refineLevel() >= RefinementRules::MAX_LEVEL) {
                    throw ApiException::of(
                        ErrorCode::ValidationFailed,
                        'This item is already at maximum refinement.',
                        ['refine_level' => $item->refineLevel(), 'max_level' => RefinementRules::MAX_LEVEL],
                    );
                }

                $this->assertTierMatches($materialId, RefinementRules::materialTierForItemLevel($item->itemLevel()));

                $goldCost = RefinementRules::goldCost($item->itemLevel(), $item->refineLevel());
                $materialCost = RefinementRules::materialCost($item->itemLevel(), $item->refineLevel());

                if ($character->gold() < $goldCost) {
                    throw ApiException::of(
                        ErrorCode::InsufficientGold,
                        sprintf('Refining this item costs %d gold.', $goldCost),
                        ['required' => $goldCost, 'available' => $character->gold()],
                    );
                }

                // Locked for the same reason GrantMaterialsHandler locks it: a
                // read-modify-write on a shared counted stack loses a
                // concurrent grant or spend without the row lock.
                $stack = $this->materialStacks->findForUpdate($character->id(), $materialId);

                if ($stack === null || $stack->quantity() < $materialCost) {
                    throw ApiException::of(
                        ErrorCode::InsufficientMaterial,
                        sprintf('Refining this item costs %d of that material.', $materialCost),
                        [
                            'required' => $materialCost,
                            'available' => $stack?->quantity() ?? 0,
                            'material_id' => $materialId,
                        ],
                    );
                }

                $now = $this->clock->now();
                $fromLevel = $item->refineLevel();

                $character->spendGold($goldCost, $now);
                $stack->consume($materialCost, $now);
                $item->refine();

                $this->refreshPowerScoreIfEquipped($character, $item);

                $this->outbox->record(ItemRefined::NAME, (new ItemRefined(
                    itemId: $item->id()->toRfc4122(),
                    characterId: $character->id()->toRfc4122(),
                    fromLevel: $fromLevel,
                    toLevel: $item->refineLevel(),
                    goldSpent: $goldCost,
                    materialId: $materialId,
                    materialSpent: $materialCost,
                ))->toArray());

                $this->audit->record(
                    AuditAction::ItemRefined,
                    [
                        'itemId' => $item->id()->toRfc4122(),
                        'fromLevel' => $fromLevel,
                        'toLevel' => $item->refineLevel(),
                        'gold' => ['spent' => $goldCost, 'balance' => $character->gold()],
                        'material' => ['id' => $materialId, 'spent' => $materialCost, 'balance' => $stack->quantity()],
                    ],
                    $accountId,
                    $character->id(),
                );

                return $item;
            },
        );
    }

    private function assertTierMatches(string $materialId, int $requiredTier): void
    {
        if (!$this->materials->has($materialId)) {
            throw ApiException::of(ErrorCode::ValidationFailed, sprintf('Unknown material "%s".', $materialId));
        }

        $material = $this->materials->get($materialId);

        if ($material->tier !== $requiredTier) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('This item requires a tier %d material.', $requiredTier),
                ['required_tier' => $requiredTier, 'material_tier' => $material->tier],
            );
        }
    }

    /**
     * Refinement changes an equipped item's contribution to equipment bonuses,
     * so the advisory power score must follow it — the same rule
     * EquipItemHandler applies, and for the same reason (ADR-0006). An
     * unequipped item contributes nothing to begin with, so there is nothing
     * to refresh.
     */
    private function refreshPowerScoreIfEquipped(Character $character, ItemInstance $item): void
    {
        if (!$item->isEquipped()) {
            return;
        }

        $character->updatePowerScore(EquipmentCalculator::sum(
            $this->items->findEquippedByCharacter($character->id()),
            $this->definitions->all(),
            $this->affixes->all(),
        ));
    }
}
