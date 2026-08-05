<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Doctrine;

use App\Feature\Inventory\Domain\Entity\VendorStock;
use App\Feature\Inventory\Domain\Repository\VendorStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

final class DoctrineVendorStockRepository implements VendorStockRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findByCharacterAndDate(Uuid $characterId, string $dateKey): ?VendorStock
    {
        /** @var VendorStock|null $stock */
        $stock = $this->entityManager
            ->getRepository(VendorStock::class)
            ->findOneBy(['characterId' => $characterId, 'dateKey' => $dateKey]);

        return $stock;
    }

    /**
     * Written as a direct `INSERT … ON CONFLICT DO NOTHING` rather than through
     * the unit of work.
     *
     * A `persist` + flush would throw on the losing side of a concurrent first
     * view, and a Doctrine flush that throws closes the EntityManager — so the
     * request that lost could no longer even read the snapshot that beat it.
     * `ON CONFLICT DO NOTHING` turns that race into a no-op followed by a plain
     * read, which is exactly the intended outcome: whoever got there first owns
     * the day.
     *
     * The statement goes to the database when it is issued, so it needs no
     * flush from the caller. It does still join whatever transaction is already
     * open on the connection — which is why BuyVendorItemHandler freezes before
     * opening its own, so a rejected buy cannot roll the freeze back.
     */
    public function freeze(VendorStock $candidate): VendorStock
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO vendor_stock (id, character_id, date_key, reference_item_level, luck, created_at)
                VALUES (:id, :characterId, :dateKey, :referenceItemLevel, :luck, :createdAt)
                ON CONFLICT (character_id, date_key) DO NOTHING
                SQL,
            [
                'id' => $candidate->id()->toRfc4122(),
                'characterId' => $candidate->characterId()->toRfc4122(),
                'dateKey' => $candidate->dateKey(),
                'referenceItemLevel' => $candidate->referenceItemLevel(),
                'luck' => $candidate->luck(),
                'createdAt' => $candidate->createdAt()->format('Y-m-d H:i:sP'),
            ],
        );

        $stored = $this->findByCharacterAndDate($candidate->characterId(), $candidate->dateKey());

        if ($stored === null) {
            // The row was either inserted just above or already there; the only
            // way to observe neither is a deleted row mid-request, which no
            // code path performs.
            throw new LogicException('Vendor stock disappeared immediately after being frozen.');
        }

        return $stored;
    }
}
