<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\AffixDefinition;
use App\Feature\Inventory\Domain\Model\AffixKind;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Model\RolledAffix;

/**
 * Rolls a concrete item from a definition.
 *
 * Pure and deterministic: the same seed and index always produce the same item,
 * so a drop is reproducible from the encounter that granted it. That is what
 * lets an investigation answer "where did this item come from" from stored data
 * rather than from trust.
 */
final class ItemGenerator
{
    /**
     * How much Luck is needed to raise the guaranteed rarity floor by one band.
     *
     * Luck raises the floor rather than the ceiling, so it is a reliable,
     * plannable investment instead of a lottery ticket. It can never make a
     * Legendary more likely. See docs/items.md section 8.
     */
    private const int LUCK_PER_FLOOR_STEP = 50;

    /** Luck can guarantee at most Rare; Epic and Legendary stay chance-only. */
    private const int MAX_LUCK_FLOOR = 2;

    private function __construct()
    {
    }

    /**
     * @param array<string, AffixDefinition> $affixes
     *
     * @return array{rarity: ItemRarity, affixes: list<RolledAffix>}
     */
    public static function generate(
        ItemDefinition $definition,
        int $itemLevel,
        int $luck,
        array $affixes,
        int $seed,
        int $index,
    ): array {
        $rarity = self::rollRarity($luck, $seed, $index);

        return [
            'rarity' => $rarity,
            'affixes' => self::rollAffixes($definition, $itemLevel, $rarity, $affixes, $seed, $index),
        ];
    }

    public static function rollRarity(int $luck, int $seed, int $index): ItemRarity
    {
        $bands = ItemRarity::ascending();
        $weights = array_map(static fn (ItemRarity $r): int => $r->dropWeight(), $bands);

        $rolled = ItemRoll::weighted($seed, ItemRoll::STREAM_RARITY, $index, $weights);
        $floor = min(self::MAX_LUCK_FLOOR, intdiv(max(0, $luck), self::LUCK_PER_FLOOR_STEP));

        return $bands[max($rolled, $floor)] ?? ItemRarity::Common;
    }

    /**
     * @param array<string, AffixDefinition> $affixes
     *
     * @return list<RolledAffix>
     */
    public static function rollAffixes(
        ItemDefinition $definition,
        int $itemLevel,
        ItemRarity $rarity,
        array $affixes,
        int $seed,
        int $index,
    ): array {
        $wanted = ItemRoll::between(
            $seed,
            ItemRoll::STREAM_AFFIX_PICK,
            $index,
            $rarity->minimumAffixes(),
            $rarity->maximumAffixes(),
        );

        if ($wanted === 0) {
            return [];
        }

        $eligible = self::eligible($definition, $itemLevel, $affixes);
        $rolled = [];
        $used = [];

        for ($slot = 0; $slot < $wanted; ++$slot) {
            // Alternating prefix and suffix keeps offensive and defensive
            // modifiers balanced on a single item, rather than letting one kind
            // fill every slot.
            $kind = $slot % 2 === 0 ? AffixKind::Prefix : AffixKind::Suffix;

            $candidates = array_values(array_filter(
                $eligible,
                static fn (AffixDefinition $a): bool => $a->kind === $kind && !isset($used[$a->id]),
            ));

            if ($candidates === []) {
                // The pool for this kind is exhausted; fall back to the other
                // rather than dropping an affix the rarity promised.
                $candidates = array_values(array_filter(
                    $eligible,
                    static fn (AffixDefinition $a): bool => !isset($used[$a->id]),
                ));
            }

            if ($candidates === []) {
                break;
            }

            $picked = $candidates[ItemRoll::below(
                $seed,
                ItemRoll::STREAM_POOL,
                $index * 16 + $slot,
                count($candidates),
            )];

            $tier = $picked->tierFor($itemLevel);

            if ($tier === null) {
                continue;
            }

            $used[$picked->id] = true;

            $rolled[] = new RolledAffix(
                $picked->id,
                $tier->tier,
                ItemRoll::between(
                    $seed,
                    ItemRoll::STREAM_AFFIX_VALUE,
                    $index * 16 + $slot,
                    $tier->minimumRoll,
                    $tier->maximumRoll,
                ),
            );
        }

        // Sorted by id so the stored order is stable regardless of roll order,
        // which keeps modifier application deterministic.
        usort($rolled, static fn (RolledAffix $a, RolledAffix $b): int => strcmp($a->affixId, $b->affixId));

        return $rolled;
    }

    /**
     * Affixes this item can carry: in one of its allowed pools, and unlocked at
     * its item level.
     *
     * @param array<string, AffixDefinition> $affixes
     *
     * @return list<AffixDefinition>
     */
    private static function eligible(ItemDefinition $definition, int $itemLevel, array $affixes): array
    {
        $eligible = [];

        foreach ($affixes as $affix) {
            if (in_array($affix->pool, $definition->allowedAffixPools, true) && $affix->isAvailableAt($itemLevel)) {
                $eligible[] = $affix;
            }
        }

        // Ordered by id: the candidate list must not depend on the iteration
        // order of the content registry, or the same seed would roll different
        // items on different machines.
        usort($eligible, static fn (AffixDefinition $a, AffixDefinition $b): int => strcmp($a->id, $b->id));

        return $eligible;
    }
}
