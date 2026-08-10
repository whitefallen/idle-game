<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A discipline owned outside the level-milestone kit — quest, dungeon or
 * reputation sourced. Existence of a row *is* ownership; there is no
 * quantity, no revocation, nothing to update once granted.
 *
 * Ownership for level-milestone disciplines stays derived, never stored —
 * see DisciplineRepository's own docblock. This table is exactly the "stored
 * half" that docblock already anticipated: `DisciplineRepository::
 * availableAtLevel()` returns the union of the level-derived set and the ids
 * this table holds for a character.
 */
#[ORM\Entity]
#[ORM\Table(name: 'character_discipline')]
// One grant per (character, discipline), enforced by the index rather than
// by an application-level check alone: it is the backstop against a
// concurrent double-grant racing past GrantDisciplineHandler's own
// already-owned check.
#[ORM\UniqueConstraint(name: 'uq_character_discipline', columns: ['character_id', 'discipline_id'])]
class CharacterDiscipline
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
    private string $disciplineId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $grantedAt;

    public function __construct(Uuid $id, Uuid $characterId, string $disciplineId, DateTimeImmutable $grantedAt)
    {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->disciplineId = $disciplineId;
        $this->grantedAt = $grantedAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function disciplineId(): string
    {
        return $this->disciplineId;
    }

    public function grantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
