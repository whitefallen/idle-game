<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Repository;

use App\Feature\Character\Domain\Entity\Character;
use Symfony\Component\Uid\Uuid;

interface CharacterRepository
{
    public function findById(Uuid $id): ?Character;

    /**
     * Locks the row for update, so that two concurrent requests cannot both
     * read pre-mutation state and both grant a reward. See docs/data-model.md
     * section 3.
     */
    public function findByIdForUpdate(Uuid $id): ?Character;

    /**
     * @return list<Character>
     */
    public function findByAccount(Uuid $accountId): array;

    public function countByAccount(Uuid $accountId): int;

    public function existsByName(string $name): bool;

    public function save(Character $character): void;
}
