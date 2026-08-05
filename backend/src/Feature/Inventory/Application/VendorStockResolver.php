<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\VendorOffer;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\VendorStockGenerator;
use App\Platform\Clock\Clock;

/**
 * Resolves a character's daily vendor stock.
 *
 * The one place that turns "a character, right now" into the inputs
 * VendorStockGenerator needs (today's date key, the character's average
 * equipped item level). Used by both the read endpoint and BuyVendorItemHandler
 * — a buy re-derives the exact offer it names from this same resolver rather
 * than trusting a price the client sent.
 */
final class VendorStockResolver
{
    public function __construct(
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<VendorOffer>
     */
    public function forCharacter(Character $character): array
    {
        $equipped = $this->items->findEquippedByCharacter($character->id());

        return VendorStockGenerator::stockFor(
            $character->id(),
            $this->clock->now()->format('Y-m-d'),
            $character->level(),
            $this->averageItemLevel($equipped),
            $character->attributes()->luck,
            $this->definitions->all(),
            $this->affixes->all(),
        );
    }

    /**
     * @param list<ItemInstance> $equipped
     */
    private function averageItemLevel(array $equipped): int
    {
        if ($equipped === []) {
            return 0;
        }

        $total = array_sum(array_map(static fn (ItemInstance $item): int => $item->itemLevel(), $equipped));

        return intdiv($total, count($equipped));
    }
}
