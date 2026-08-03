<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Repository;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use Symfony\Component\Uid\Uuid;

interface ItemInstanceRepository
{
    public function findById(Uuid $id): ?ItemInstance;

    /**
     * @return list<ItemInstance>
     */
    public function findByCharacter(Uuid $characterId): array;

    /**
     * Only what the character is wearing. Used on every stat calculation, so it
     * is a narrower query than loading the whole inventory.
     *
     * @return list<ItemInstance>
     */
    public function findEquippedByCharacter(Uuid $characterId): array;

    public function countByCharacter(Uuid $characterId): int;

    public function save(ItemInstance $item): void;

    public function remove(ItemInstance $item): void;
}
