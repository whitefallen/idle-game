<?php

declare(strict_types=1);

namespace App\Feature\Quest\Infrastructure\Doctrine;

use App\Feature\Quest\Domain\Entity\QuestRun;
use App\Feature\Quest\Domain\Repository\QuestRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineQuestRunRepository implements QuestRunRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findForCharacterAndQuest(Uuid $characterId, string $questId): ?QuestRun
    {
        return $this->entityManager
            ->getRepository(QuestRun::class)
            ->findOneBy(['characterId' => $characterId, 'questId' => $questId]);
    }

    public function findForCharacterAndQuestForUpdate(Uuid $characterId, string $questId): ?QuestRun
    {
        /** @var QuestRun|null $run */
        $run = $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(QuestRun::class, 'r')
            ->where('r.characterId = :character')
            ->andWhere('r.questId = :quest')
            ->setParameter('character', $characterId, 'uuid')
            ->setParameter('quest', $questId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $run;
    }

    public function findAllForCharacter(Uuid $characterId): array
    {
        $runs = $this->entityManager
            ->getRepository(QuestRun::class)
            ->findBy(['characterId' => $characterId]);

        $byQuestId = [];

        foreach ($runs as $run) {
            $byQuestId[$run->questId()] = $run;
        }

        return $byQuestId;
    }

    public function save(QuestRun $run): void
    {
        $this->entityManager->persist($run);
    }
}
