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

    /**
     * Most recent first.
     *
     * `created_at` is stored at one-second precision, so two encounters
     * resolved inside the same second carry an identical sort key and the
     * database is free to return them in any order. The primary key breaks the
     * tie: ids are UUIDv7 (ADR-0005) and therefore time-ordered, so ties resolve
     * in true creation order rather than arbitrarily. Determinism rule R3 in
     * docs/combat.md asks for the same thing wherever a natural order runs out.
     *
     * The Vigor activity gate now keeps two encounters from landing in the same
     * second through the API, but that is a tunable gameplay rule, not a
     * guarantee this query is entitled to lean on.
     */
    public function findRecentForCharacter(Uuid $characterId, int $limit): array
    {
        /** @var list<Encounter> $encounters */
        $encounters = $this->entityManager
            ->getRepository(Encounter::class)
            ->findBy(
                ['characterId' => $characterId],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
                max(1, min(50, $limit)),
            );

        return $encounters;
    }

    public function save(Encounter $encounter): void
    {
        $this->entityManager->persist($encounter);
    }
}
