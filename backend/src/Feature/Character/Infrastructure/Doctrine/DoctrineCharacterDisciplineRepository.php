<?php

declare(strict_types=1);

namespace App\Feature\Character\Infrastructure\Doctrine;

use App\Feature\Character\Domain\Entity\CharacterDiscipline;
use App\Feature\Character\Domain\Repository\CharacterDisciplineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineCharacterDisciplineRepository implements CharacterDisciplineRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function idsForCharacter(Uuid $characterId): array
    {
        /** @var list<CharacterDiscipline> $rows */
        $rows = $this->entityManager
            ->getRepository(CharacterDiscipline::class)
            ->findBy(['characterId' => $characterId]);

        return array_map(static fn (CharacterDiscipline $row): string => $row->disciplineId(), $rows);
    }

    public function save(CharacterDiscipline $discipline): void
    {
        $this->entityManager->persist($discipline);
    }
}
