<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Repository;

use App\Feature\Quest\Domain\Entity\QuestRun;
use Symfony\Component\Uid\Uuid;

interface QuestRunRepository
{
    public function findForCharacterAndQuest(Uuid $characterId, string $questId): ?QuestRun;

    /**
     * Locked for update. Both accept and claim read-modify-write this row,
     * and an unlocked read lets two concurrent claims both resolve the same
     * fight and both grant its reward.
     */
    public function findForCharacterAndQuestForUpdate(Uuid $characterId, string $questId): ?QuestRun;

    /**
     * @return array<string, QuestRun> Keyed by quest id, for building the
     *                                 per-character quest list in one query.
     */
    public function findAllForCharacter(Uuid $characterId): array;

    public function save(QuestRun $run): void;
}
