<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Repository;

use App\Feature\Dungeon\Domain\Entity\DungeonRun;
use Symfony\Component\Uid\Uuid;

interface DungeonRunRepository
{
    /**
     * @return list<DungeonRun> Most recent first.
     */
    public function findRecentForCharacter(Uuid $characterId, int $limit): array;

    public function save(DungeonRun $run): void;
}
