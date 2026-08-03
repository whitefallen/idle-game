<?php

declare(strict_types=1);

namespace App\Feature\Character\Infrastructure\Doctrine;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineCharacterRepository implements CharacterRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findById(Uuid $id): ?Character
    {
        return $this->entityManager->find(Character::class, $id);
    }

    public function findByIdForUpdate(Uuid $id): ?Character
    {
        return $this->entityManager->find(Character::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function findByAccount(Uuid $accountId): array
    {
        /** @var list<Character> $characters */
        $characters = $this->entityManager
            ->getRepository(Character::class)
            ->findBy(['accountId' => $accountId], ['createdAt' => 'ASC']);

        return $characters;
    }

    public function countByAccount(Uuid $accountId): int
    {
        return $this->entityManager
            ->getRepository(Character::class)
            ->count(['accountId' => $accountId]);
    }

    public function existsByName(string $name): bool
    {
        return $this->entityManager
            ->getRepository(Character::class)
            ->count(['name' => $name]) > 0;
    }

    public function save(Character $character): void
    {
        $this->entityManager->persist($character);
    }
}
