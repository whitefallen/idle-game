<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Entity\VendorStock;
use App\Feature\Inventory\Domain\Model\VendorOffer;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Repository\VendorStockRepository;
use App\Feature\Inventory\Domain\Service\VendorRules;
use App\Feature\Inventory\Domain\Service\VendorStockGenerator;
use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;

/**
 * Resolves a character's daily vendor stock.
 *
 * The one place that turns "a character, right now" into the inputs
 * VendorStockGenerator needs: today's date key, and the character's frozen
 * reference item level and Luck. Used by both the read endpoint and
 * BuyVendorItemHandler — a buy re-derives the exact offer it names from this
 * same resolver rather than trusting a price the client sent.
 *
 * **The freeze is the point.** Seeding the roll on (character, date) already
 * made a page refresh harmless, but the roll also consumed the character's
 * live level, Luck and average equipped item level, so any of those changing
 * re-rolled the day's eight offers — unequip a weapon, reload, new stock, as
 * many times as a player cares to. Those inputs are captured into a VendorStock
 * row the first time the day's stock is resolved, and every later resolve that
 * day reads the row instead of the character. See docs/vendor.md section 2.
 */
final class VendorStockResolver
{
    public function __construct(
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
        private readonly VendorStockRepository $stocks,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<VendorOffer>
     */
    public function forCharacter(Character $character): array
    {
        $frozen = $this->ensureFrozen($character);

        return VendorStockGenerator::stockFor(
            $character->id(),
            $frozen->dateKey(),
            $frozen->referenceItemLevel(),
            $frozen->luck(),
            $this->definitions->all(),
            $this->affixes->all(),
        );
    }

    /**
     * Captures today's inputs if this is the first time the character has
     * resolved stock today, and returns the snapshot in force either way.
     *
     * Idempotent, and safe to call ahead of a transaction that might roll back
     * — which is exactly why it is public. See BuyVendorItemHandler.
     */
    public function ensureFrozen(Character $character): VendorStock
    {
        $dateKey = $this->clock->now()->format('Y-m-d');
        $existing = $this->stocks->findByCharacterAndDate($character->id(), $dateKey);

        if ($existing !== null) {
            return $existing;
        }

        return $this->stocks->freeze(new VendorStock(
            $this->identifiers->generate(),
            $character->id(),
            $dateKey,
            VendorRules::referenceItemLevel(
                $character->level(),
                $this->averageItemLevel($this->items->findEquippedByCharacter($character->id())),
            ),
            $character->attributes()->luck,
            $this->clock->now(),
        ));
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
