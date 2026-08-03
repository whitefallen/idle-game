<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Doctrine;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineItemInstanceRepository implements ItemInstanceRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findById(Uuid $id): ?ItemInstance
    {
        return $this->entityManager->find(ItemInstance::class, $id);
    }

    public function findByCharacter(Uuid $characterId): array
    {
        /** @var list<ItemInstance> $items */
        $items = $this->entityManager
            ->getRepository(ItemInstance::class)
            ->findBy(['characterId' => $characterId], ['createdAt' => 'DESC']);

        return $items;
    }

    public function findEquippedByCharacter(Uuid $characterId): array
    {
        /** @var list<ItemInstance> $items */
        $items = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(ItemInstance::class, 'i')
            ->where('i.characterId = :character')
            ->andWhere('i.equippedSlot IS NOT NULL')
            ->orderBy('i.equippedSlot', 'ASC')
            ->setParameter('character', $characterId, 'uuid')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countByCharacter(Uuid $characterId): int
    {
        return $this->entityManager
            ->getRepository(ItemInstance::class)
            ->count(['characterId' => $characterId]);
    }

    public function save(ItemInstance $item): void
    {
        $this->entityManager->persist($item);
    }

    public function remove(ItemInstance $item): void
    {
        $this->entityManager->remove($item);
    }
}
