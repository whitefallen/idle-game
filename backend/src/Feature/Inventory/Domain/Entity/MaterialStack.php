<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * How much of one material a character holds.
 *
 * Materials are fungible, so they are stored as a counted stack rather than as
 * one row per unit — the alternative would put tens of thousands of identical
 * rows behind a single refinement.
 *
 * The stack is a row per (character, material) pair rather than a JSON column
 * on the character, because the two writers — drops and Holding claims — must
 * be able to increment one material under a row lock without serialising every
 * other currency mutation the character makes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'character_material')]
// The guarantee that a character has at most one stack per material. Without
// it, two concurrent first-time grants each insert a row and half the balance
// becomes invisible to every later read.
#[ORM\UniqueConstraint(name: 'uq_character_material', columns: ['character_id', 'material_id'])]
class MaterialStack
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    /**
     * A content id string, not a foreign key: definitions live in content/,
     * not in the database. Integrity is enforced by the content pipeline.
     */
    #[ORM\Column(type: 'string', length: 120)]
    private string $materialId;

    #[ORM\Column(type: 'bigint')]
    private int $quantity = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $characterId, string $materialId, DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->materialId = $materialId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function materialId(): string
    {
        return $this->materialId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function add(int $amount, DateTimeImmutable $now): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Use consume to reduce a material stack.');
        }

        $this->quantity += $amount;
        $this->updatedAt = $now;
    }

    /**
     * Refinement is the only sink the design gives materials — crafting, the
     * other candidate, is out of scope (docs/architecture.md section 9.2).
     * Present now so that the invariant — a stack never goes
     * negative — is stated once, in the entity, rather than being rediscovered
     * by the first caller that needs to spend.
     */
    public function consume(int $amount, DateTimeImmutable $now): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Material consumption cannot be negative.');
        }

        if ($this->quantity < $amount) {
            throw new DomainException('Insufficient material.');
        }

        $this->quantity -= $amount;
        $this->updatedAt = $now;
    }
}
