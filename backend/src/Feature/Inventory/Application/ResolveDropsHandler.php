<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\DropEntryKind;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\DropTableRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\ItemGenerator;
use App\Feature\Inventory\Domain\Service\ItemRoll;
use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves a drop table into items.
 *
 * Every roll derives from the encounter's seed, so the drop is reproducible
 * from stored data: an investigation into "where did this item come from" can
 * replay the same table against the same seed rather than taking the record on
 * trust. All rolls happen server-side; the client is told what it received and
 * never what it could have received.
 *
 * Items are persisted but not flushed — the caller owns the transaction, so a
 * rolled-back encounter grants no loot.
 */
final class ResolveDropsHandler
{
    /**
     * A hard ceiling on how many items one character may hold.
     *
     * Space is an Emberdust convenience purchase, never a gold sink; this is
     * the free allowance. See docs/economy.md section 6.
     */
    public const int INVENTORY_CAPACITY = 60;

    public function __construct(
        private readonly DropTableRepository $tables,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly ItemInstanceRepository $items,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{items: list<ItemInstance>, materials: array<string, int>}
     */
    public function __invoke(Uuid $characterId, string $dropTableId, int $luck, int $seed): array
    {
        try {
            $table = $this->tables->get($dropTableId);
        } catch (InvalidArgumentException) {
            // A missing table must not fail the fight that earned it. The
            // encounter already happened; losing the loot is recoverable,
            // losing the encounter is not.
            return ['items' => [], 'materials' => []];
        }

        $now = $this->clock->now();
        $held = $this->items->countByCharacter($characterId);

        $granted = [];
        $materials = [];

        for ($roll = 0; $roll < $table->rolls; ++$roll) {
            $entry = $table->entries[
                ItemRoll::weighted($seed, ItemRoll::STREAM_TABLE_ENTRY, $roll, $table->weights())
            ] ?? null;

            if ($entry === null || $entry->kind === DropEntryKind::Nothing) {
                continue;
            }

            if ($entry->kind === DropEntryKind::Material && $entry->materialId !== null) {
                $quantity = ItemRoll::between(
                    $seed,
                    ItemRoll::STREAM_QUANTITY,
                    $roll,
                    $entry->minimumQuantity,
                    $entry->maximumQuantity,
                );

                $materials[$entry->materialId] = ($materials[$entry->materialId] ?? 0) + $quantity;

                continue;
            }

            if ($entry->kind !== DropEntryKind::Item || $entry->pool === null) {
                continue;
            }

            if ($held + count($granted) >= self::INVENTORY_CAPACITY) {
                // Full. Dropping silently is wrong, but the alternative —
                // failing the encounter — is worse; the caller reports it.
                break;
            }

            $item = $this->rollItem($characterId, $entry->pool, $entry->minimumItemLevel, $entry->maximumItemLevel, $luck, $seed, $roll, $now);

            if ($item !== null) {
                $this->items->save($item);
                $granted[] = $item;
            }
        }

        return ['items' => $granted, 'materials' => $materials];
    }

    private function rollItem(
        Uuid $characterId,
        string $pool,
        int $minimumItemLevel,
        int $maximumItemLevel,
        int $luck,
        int $seed,
        int $roll,
        DateTimeImmutable $now,
    ): ?ItemInstance {
        $candidates = $this->definitions->inPool($pool);

        if ($candidates === []) {
            return null;
        }

        // Ordered by id so the same seed picks the same item regardless of how
        // the content registry iterated.
        usort($candidates, static fn ($a, $b): int => strcmp($a->id, $b->id));

        $definition = $candidates[ItemRoll::below($seed, ItemRoll::STREAM_POOL, $roll, count($candidates))];

        $itemLevel = ItemRoll::between(
            $seed,
            ItemRoll::STREAM_ITEM_LEVEL,
            $roll,
            max(1, $minimumItemLevel),
            max(1, $maximumItemLevel),
        );

        $rolled = ItemGenerator::generate($definition, $itemLevel, $luck, $this->affixes->all(), $seed, $roll);

        return new ItemInstance(
            $this->identifiers->generate(),
            $characterId,
            $definition->id,
            $itemLevel,
            $rolled['rarity'],
            $rolled['affixes'],
            $now,
        );
    }
}
