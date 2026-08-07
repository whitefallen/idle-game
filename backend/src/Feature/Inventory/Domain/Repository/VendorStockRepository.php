<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Entity\VendorStock;
use Symfony\Component\Uid\Uuid;

interface VendorStockRepository
{
    public function findByCharacterAndDate(Uuid $characterId, string $dateKey): ?VendorStock;

    /**
     * Stores $candidate unless this character already has a snapshot for that
     * day, and returns whichever snapshot is now authoritative.
     *
     * Expressed as one operation rather than a find-then-save by the caller,
     * because the two are racing: every vendor read of the day tries to freeze,
     * and a check that happens before the insert always loses to a concurrent
     * request. The unique index is the real guarantee; this method is how the
     * loser of that race finds out and adopts the winner's snapshot instead of
     * failing the request.
     *
     * Reaches the database when it is called, so no caller has to flush. It
     * still commits with whatever transaction is already open, which is why a
     * caller whose transaction can roll back — a buy that turns out to be
     * unaffordable — must freeze before opening it, or hand back the very
     * reroll this table exists to prevent.
     */
    public function freeze(VendorStock $candidate): VendorStock;
}
