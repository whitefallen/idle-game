<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Infrastructure\Doctrine;

use App\Feature\Encounter\Domain\Entity\Encounter;
use App\Feature\Encounter\Domain\Repository\EncounterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineEncounterRepository implements EncounterRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findById(Uuid $id): ?Encounter
    {
        return $this->entityManager->find(Encounter::class, $id);
    }

    public function findRecentForCharacter(Uuid $characterId, int $limit): array
    {
        /** @var list<Encounter> $encounters */
        $encounters = $this->entityManager
            ->getRepository(Encounter::class)
            ->findBy(['characterId' => $characterId], ['createdAt' => 'DESC'], max(1, min(50, $limit)));

        return $encounters;
    }

    public function save(Encounter $encounter): void
    {
        $this->entityManager->persist($encounter);
    }
}
