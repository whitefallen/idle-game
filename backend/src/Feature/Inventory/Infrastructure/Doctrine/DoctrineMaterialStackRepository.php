<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Doctrine;

use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineMaterialStackRepository implements MaterialStackRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findByCharacter(Uuid $characterId): array
    {
        /** @var list<MaterialStack> $stacks */
        $stacks = $this->entityManager
            ->getRepository(MaterialStack::class)
            ->findBy(['characterId' => $characterId], ['materialId' => 'ASC']);

        return $stacks;
    }

    public function findForUpdate(Uuid $characterId, string $materialId): ?MaterialStack
    {
        /** @var MaterialStack|null $stack */
        $stack = $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(MaterialStack::class, 's')
            ->where('s.characterId = :character')
            ->andWhere('s.materialId = :material')
            ->setParameter('character', $characterId, 'uuid')
            ->setParameter('material', $materialId)
            ->getQuery()
            // SELECT … FOR UPDATE. The caller is inside a transaction; the lock
            // is held until it commits, which is what makes the surrounding
            // read-modify-write atomic against a concurrent grant.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $stack;
    }

    public function save(MaterialStack $stack): void
    {
        $this->entityManager->persist($stack);
    }
}
