<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Repository;

use App\Feature\Character\Domain\Entity\CharacterDiscipline;
use Symfony\Component\Uid\Uuid;

interface CharacterDisciplineRepository
{
    /**
     * @return list<string> Discipline ids this character owns outside the
     *                      level-derived kit. Unordered; callers that need a
     *                      stable order sort it themselves.
     */
    public function idsForCharacter(Uuid $characterId): array;

    public function save(CharacterDiscipline $discipline): void;
}
