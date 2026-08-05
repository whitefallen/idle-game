<?php

declare(strict_types=1);

namespace App\Feature\Holding\Infrastructure\Doctrine;

use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Repository\HoldingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineHoldingRepository implements HoldingRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findByCharacter(Uuid $characterId): ?Holding
    {
        return $this->entityManager
            ->getRepository(Holding::class)
            ->findOneBy(['characterId' => $characterId]);
    }

    public function findByCharacterForUpdate(Uuid $characterId): ?Holding
    {
        /** @var Holding|null $holding */
        $holding = $this->entityManager
            ->createQueryBuilder()
            ->select('h')
            ->from(Holding::class, 'h')
            ->where('h.characterId = :character')
            ->setParameter('character', $characterId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $holding;
    }

    public function save(Holding $holding): void
    {
        $this->entityManager->persist($holding);
    }
}
