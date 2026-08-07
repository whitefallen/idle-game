<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\AffixDefinition;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\VendorOffer;
use Symfony\Component\Uid\Uuid;

/**
 * Rolls a character's daily vendor stock.
 *
 * Pure and deterministic, the same way ItemGenerator is: the same character,
 * date, reference item level, luck and content produce the same stock every
 * time, so no offer needs to be stored and a buy request can be verified by
 * re-deriving the exact offer it names rather than trusting the client's copy
 * of it.
 *
 * Determinism is only worth as much as the stability of the inputs, which is
 * why the reference item level and Luck arrive as arguments already frozen for
 * the day (VendorStock) rather than being read live off the character here —
 * live inputs meant a player could re-roll the day's stock by unequipping a
 * weapon. This class stays pure either way; it simply must not be handed a
 * moving target.
 *
 * The seed is derived from the character and the day, not from any encounter
 * — vendor rolls must never correlate with, or be influenced by, combat loot
 * rolls (see docs/items.md section 9.3 for why loot and combat RNG are kept
 * separate; the same reasoning applies a second time here).
 */
final class VendorStockGenerator
{
    private function __construct()
    {
    }

    /**
     * @param array<string, ItemDefinition>  $definitions
     * @param array<string, AffixDefinition> $affixes
     *
     * @return list<VendorOffer>
     */
    public static function stockFor(
        Uuid $characterId,
        string $dateKey,
        int $referenceItemLevel,
        int $luck,
        array $definitions,
        array $affixes,
    ): array {
        [$minItemLevel, $maxItemLevel] = VendorRules::stockItemLevelBand($referenceItemLevel);
        $seed = self::seedFor($characterId, $dateKey);

        $candidates = self::candidates($definitions, $minItemLevel, $maxItemLevel, $referenceItemLevel);

        if ($candidates === []) {
            return [];
        }

        $offers = [];

        for ($index = 0; $index < VendorRules::STOCK_SIZE; ++$index) {
            $definition = $candidates[ItemRoll::below($seed, ItemRoll::STREAM_POOL, $index, count($candidates))];
            $itemLevel = ItemRoll::between($seed, ItemRoll::STREAM_ITEM_LEVEL, $index, $minItemLevel, $maxItemLevel);
            $rarity = VendorRules::capRarity(ItemGenerator::rollRarity($luck, $seed, $index));
            $rolledAffixes = ItemGenerator::rollAffixes($definition, $itemLevel, $rarity, $affixes, $seed, $index);

            $offers[] = new VendorOffer(
                $index,
                $definition->id,
                $itemLevel,
                $rarity,
                $rolledAffixes,
                VendorRules::buyPrice($definition->vendorValue, $itemLevel, $referenceItemLevel, $rarity),
            );
        }

        return $offers;
    }

    /**
     * Definitions whose own item level falls inside the stock band — base
     * stats derive from the definition's item level, not the rolled instance
     * level (items.md section 1), so a definition far outside the band would
     * roll a correctly-priced but power-mismatched item. Falls back to the
     * closest definitions by item level when the content corpus is too thin
     * to fill the band, which it currently is (docs/content.md section 5) —
     * an empty stock would be a worse failure than a slightly wider one.
     *
     * @param array<string, ItemDefinition> $definitions
     *
     * @return list<ItemDefinition>
     */
    private static function candidates(
        array $definitions,
        int $minItemLevel,
        int $maxItemLevel,
        int $referenceItemLevel,
    ): array {
        $all = array_values($definitions);
        usort($all, static fn (ItemDefinition $a, ItemDefinition $b): int => strcmp($a->id, $b->id));

        $inBand = array_values(array_filter(
            $all,
            static fn (ItemDefinition $d): bool => $d->itemLevel >= $minItemLevel && $d->itemLevel <= $maxItemLevel,
        ));

        if ($inBand !== []) {
            return $inBand;
        }

        if ($all === []) {
            return [];
        }

        usort(
            $all,
            static fn (ItemDefinition $a, ItemDefinition $b): int => abs($a->itemLevel - $referenceItemLevel)
                <=> abs($b->itemLevel - $referenceItemLevel),
        );

        return array_slice($all, 0, min(count($all), 5));
    }

    private static function seedFor(Uuid $characterId, string $dateKey): int
    {
        return crc32($characterId->toRfc4122() . '|vendor|' . $dateKey);
    }
}
