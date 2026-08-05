<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Repository;

use App\Feature\Holding\Domain\Entity\Holding;
use Symfony\Component\Uid\Uuid;

interface HoldingRepository
{
    public function findByCharacter(Uuid $characterId): ?Holding;

    /**
     * The Holding, locked for update.
     *
     * Every claim goes through here (docs/idle.md rule T2). Without the lock,
     * two concurrent claims read the same anchors and both pay out in full —
     * the exploit the rule exists to close.
     */
    public function findByCharacterForUpdate(Uuid $characterId): ?Holding;

    public function save(Holding $holding): void;
}
