<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Infrastructure\Doctrine;

use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use App\Feature\Dungeon\Domain\Repository\DungeonRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineDungeonRunRepository implements DungeonRunRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Most recent first. Ties on `created_at` break on id, which is a
     * time-ordered UUIDv7 (ADR-0005) — same rationale as
     * DoctrineEncounterRepository::findRecentForCharacter().
     */
    public function findRecentForCharacter(Uuid $characterId, int $limit): array
    {
        /** @var list<DungeonRun> $runs */
        $runs = $this->entityManager
            ->getRepository(DungeonRun::class)
            ->findBy(
                ['characterId' => $characterId],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
                max(1, min(50, $limit)),
            );

        return $runs;
    }

    public function findByIdForUpdate(Uuid $id): ?DungeonRun
    {
        /** @var DungeonRun|null $run */
        $run = $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(DungeonRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $run;
    }

    public function hasCleared(Uuid $characterId, string $dungeonId): bool
    {
        $count = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DungeonRun::class, 'r')
            ->where('r.characterId = :character')
            ->andWhere('r.dungeonId = :dungeon')
            ->andWhere('r.cleared = true')
            ->setParameter('character', $characterId, 'uuid')
            ->setParameter('dungeon', $dungeonId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    public function save(DungeonRun $run): void
    {
        $this->entityManager->persist($run);
    }
}
