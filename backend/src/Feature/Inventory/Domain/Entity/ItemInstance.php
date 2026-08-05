<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Entity;

use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Model\RolledAffix;
use App\Feature\Inventory\Domain\Service\RefinementRules;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * One rolled copy of an item, owned by a character.
 *
 * Stores a reference to its definition plus its rolled state — never a copy of
 * the definition's static data. See docs/items.md section 1.
 *
 * Uniqueness of the equipped slot is enforced by a partial unique index on
 * (character_id, equipped_slot) WHERE equipped_slot IS NOT NULL, because an
 * application check alone loses to a concurrent request.
 */
#[ORM\Entity]
#[ORM\Table(name: 'item_instance')]
#[ORM\Index(name: 'idx_item_instance_character_id', columns: ['character_id'])]
// The guarantee that a slot holds at most one item. Partial, because an
// unequipped item has a NULL slot and any number of those may exist. An
// application-level check alone loses to a concurrent request; this does not.
#[ORM\UniqueConstraint(
    name: 'uq_item_instance_equipped',
    columns: ['character_id', 'equipped_slot'],
    options: ['where' => '(equipped_slot IS NOT NULL)'],
)]
class ItemInstance
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    /**
     * A content id string, not a foreign key: definitions live in content/,
     * not in the database. Integrity is enforced by the content pipeline's CI
     * check.
     */
    #[ORM\Column(type: 'string', length: 120)]
    private string $definitionId;

    #[ORM\Column(type: 'integer')]
    private int $itemLevel;

    #[ORM\Column(type: 'string', length: 20, enumType: ItemRarity::class)]
    private ItemRarity $rarity;

    /**
     * JSONB because affixes are variable-length, read-mostly, and always read
     * whole. A normalised table would triple the row count of the largest table
     * in the schema and add a join to every inventory read, for query
     * flexibility nothing needs. See docs/data-model.md section 2.
     *
     * @var list<array{id: string, tier: int, value: int}>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $affixes;

    /** Null means the item is in the inventory rather than worn. */
    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: EquipmentSlot::class)]
    private ?EquipmentSlot $equippedSlot = null;

    /**
     * 0 to RefinementRules::MAX_LEVEL. Every new item starts unrefined, so this
     * is never a constructor argument — only ever advanced one level at a time
     * by refine().
     *
     * Carries a database-level default, unlike this entity's other integer
     * columns: those were only ever created by a fresh CREATE TABLE, but this
     * one was added to a table that may already hold rows, and "unrefined" is
     * the only correct backfill for every item that predates refinement — not
     * a placeholder standing in for a real migration.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $refineLevel = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<RolledAffix> $affixes
     */
    public function __construct(
        Uuid $id,
        Uuid $characterId,
        string $definitionId,
        int $itemLevel,
        ItemRarity $rarity,
        array $affixes,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->definitionId = $definitionId;
        $this->itemLevel = $itemLevel;
        $this->rarity = $rarity;
        $this->affixes = array_map(static fn (RolledAffix $a): array => $a->toArray(), $affixes);
        $this->createdAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function isOwnedBy(Uuid $characterId): bool
    {
        return $this->characterId->equals($characterId);
    }

    public function definitionId(): string
    {
        return $this->definitionId;
    }

    public function itemLevel(): int
    {
        return $this->itemLevel;
    }

    public function rarity(): ItemRarity
    {
        return $this->rarity;
    }

    /**
     * @return list<RolledAffix>
     */
    public function affixes(): array
    {
        return array_map(RolledAffix::fromArray(...), $this->affixes);
    }

    public function equippedSlot(): ?EquipmentSlot
    {
        return $this->equippedSlot;
    }

    public function isEquipped(): bool
    {
        return $this->equippedSlot !== null;
    }

    public function equipTo(EquipmentSlot $slot): void
    {
        $this->equippedSlot = $slot;
    }

    public function unequip(): void
    {
        $this->equippedSlot = null;
    }

    public function refineLevel(): int
    {
        return $this->refineLevel;
    }

    /**
     * Advances refinement by exactly one level. There is no failure chance and
     * nothing to roll — the only thing that can go wrong is attempting a level
     * past the cap, which is a defensive invariant rather than a gameplay
     * outcome: the handler is expected to check first and never call this once
     * a player is actually at the cap.
     */
    public function refine(): void
    {
        if ($this->refineLevel >= RefinementRules::MAX_LEVEL) {
            throw new DomainException('This item is already at maximum refinement.');
        }

        ++$this->refineLevel;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
