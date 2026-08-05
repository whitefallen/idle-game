<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * One rolled slot in a character's daily vendor stock.
 *
 * Never persisted: it is fully reproducible from (characterId, date, index),
 * so it is recomputed on every read the same way the Holding's accrual is —
 * see docs/idle.md section 7.2. A buy request names an offer by its index and
 * the server re-derives the same offer to price and mint it, rather than
 * trusting anything the client saw.
 */
final readonly class VendorOffer
{
    /**
     * @param list<RolledAffix> $affixes
     */
    public function __construct(
        public int $index,
        public string $definitionId,
        public int $itemLevel,
        public ItemRarity $rarity,
        public array $affixes,
        public int $price,
    ) {
    }
}
