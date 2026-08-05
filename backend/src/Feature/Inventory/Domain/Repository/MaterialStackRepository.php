<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Entity\MaterialStack;
use Symfony\Component\Uid\Uuid;

interface MaterialStackRepository
{
    /**
     * @return list<MaterialStack> Ordered by material id, so a rendered stash
     *                             does not reorder itself between requests.
     */
    public function findByCharacter(Uuid $characterId): array;

    /**
     * The stack for one material, locked for update.
     *
     * Locked because both writers — encounter drops and Holding claims —
     * read-modify-write the same row, and an unlocked increment loses one of
     * two concurrent grants. Returns null when the character holds none yet;
     * there is nothing to lock in that case and the caller creates the stack.
     */
    public function findForUpdate(Uuid $characterId, string $materialId): ?MaterialStack;

    public function save(MaterialStack $stack): void;
}
