<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\EquipmentCalculator;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\DuplicateKeyException;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Equips an item, displacing whatever occupied the slot.
 *
 * Requirements are checked server-side on every equip, not only when the item
 * drops: a character can lose the attributes an item needed by respeccing, and
 * the check must hold at the moment the item is actually worn.
 */
final class EquipItemHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $itemId, ?EquipmentSlot $requestedSlot = null): ItemInstance
    {
        return $this->transactions->transactional(
            function () use ($accountId, $itemId, $requestedSlot): ItemInstance {
                $item = $this->items->findById($itemId);

                if ($item === null) {
                    throw ApiException::notFound('Item');
                }

                // Locked, because equipping reads the slot's current occupant
                // and then writes both rows.
                $character = $this->characters->findByIdForUpdate($item->characterId());

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Item');
                }

                $definition = $this->definitions->get($item->definitionId());
                $slot = $this->resolveSlot($definition->slot, $requestedSlot);

                $this->assertRequirementsMet($character->level(), $character->attributes()->toArray(), $definition);

                $equipped = $this->items->findEquippedByCharacter($character->id());

                foreach ($this->slotsToClear($slot, $definition->twoHanded) as $occupied) {
                    foreach ($equipped as $worn) {
                        if ($worn->equippedSlot() === $occupied && !$worn->id()->equals($item->id())) {
                            $worn->unequip();
                        }
                    }
                }

                // Flushed before the new item takes the slot. Within a single
                // flush Doctrine may order the two UPDATEs either way, and if
                // the new slot is written before the old one is cleared the
                // partial unique index rejects it — an equip that should have
                // succeeded fails as a conflict.
                $this->transactions->commit();

                // A two-handed weapon occupies the main hand and locks the off
                // hand, so anything already there has come off above.
                $item->equipTo($slot);

                try {
                    $this->transactions->commit();
                } catch (DuplicateKeyException) {
                    // The partial unique index on (character_id, equipped_slot)
                    // is the real guarantee; this converts losing that race
                    // into a sensible error rather than a 500.
                    throw ApiException::of(
                        ErrorCode::Conflict,
                        'That slot was filled by another request. Try again.',
                    );
                }

                // After the flush, not before: the score is derived from a
                // query of what is equipped, so reading it earlier would see
                // the state this request just replaced.
                $this->refreshPowerScore($character);

                return $item;
            },
        );
    }

    /**
     * Rings may go in either ring slot, so the caller may choose. Everything
     * else goes where its definition says.
     */
    private function resolveSlot(EquipmentSlot $natural, ?EquipmentSlot $requested): EquipmentSlot
    {
        if ($requested === null) {
            return $natural;
        }

        if (!in_array($requested, EquipmentSlot::interchangeableWith($natural), true)) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('That item cannot be worn in the %s slot.', $requested->value),
                ['slot' => $requested->value, 'allowed' => $natural->value],
            );
        }

        return $requested;
    }

    /**
     * @return list<EquipmentSlot>
     */
    private function slotsToClear(EquipmentSlot $slot, bool $twoHanded): array
    {
        if ($twoHanded) {
            return [EquipmentSlot::MainHand, EquipmentSlot::OffHand];
        }

        // Equipping an off-hand while a two-handed weapon is worn must displace
        // that weapon, or the character would be holding three hands' worth.
        if ($slot === EquipmentSlot::OffHand) {
            return [EquipmentSlot::OffHand, EquipmentSlot::MainHand];
        }

        return [$slot];
    }

    /**
     * @param array<string, int> $attributes
     */
    private function assertRequirementsMet(int $level, array $attributes, ItemDefinition $definition): void
    {
        if ($level < $definition->requiredLevel) {
            throw ApiException::of(
                ErrorCode::RequirementNotMet,
                sprintf('This item requires level %d.', $definition->requiredLevel),
                ['required_level' => $definition->requiredLevel, 'character_level' => $level],
            );
        }

        foreach ($definition->attributeRequirements as $code => $required) {
            $current = $attributes[$code] ?? 0;

            if ($current < $required) {
                throw ApiException::of(
                    ErrorCode::RequirementNotMet,
                    sprintf('This item requires %d %s.', $required, $code),
                    ['attribute' => $code, 'required' => $required, 'current' => $current],
                );
            }
        }
    }

    /**
     * The advisory ranking column has to follow equipment, or leaderboards and
     * matchmaking rank players by gear they are no longer wearing. See ADR-0006.
     */
    private function refreshPowerScore(\App\Feature\Character\Domain\Entity\Character $character): void
    {
        $character->updatePowerScore(EquipmentCalculator::sum(
            $this->items->findEquippedByCharacter($character->id()),
            $this->definitions->all(),
            $this->affixes->all(),
        ));
    }
}
