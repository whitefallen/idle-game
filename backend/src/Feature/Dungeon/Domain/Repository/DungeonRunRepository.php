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

    /**
     * Locked for update — the discipline-pick endpoint reads a specific run
     * and then writes its pick, and two concurrent picks against the same
     * run must not both succeed.
     */
    public function findByIdForUpdate(Uuid $id): ?DungeonRun;

    /**
     * Whether this character has a **cleared** run of this dungeon. A failed
     * attempt does not count — only a clear locks a `repeatable: false`
     * dungeon out for good. See docs/dungeons.md section 2.
     */
    public function hasCleared(Uuid $characterId, string $dungeonId): bool;

    public function save(DungeonRun $run): void;
}
