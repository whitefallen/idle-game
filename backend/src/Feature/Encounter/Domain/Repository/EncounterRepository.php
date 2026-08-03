<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Repository;

use App\Feature\Encounter\Domain\Entity\Encounter;
use Symfony\Component\Uid\Uuid;

/**
 * Persisted encounter results. Distinct from
 * {@see EncounterDefinitionRepository}, which serves authored content.
 */
interface EncounterRepository
{
    public function findById(Uuid $id): ?Encounter;

    /**
     * @return list<Encounter>
     */
    public function findRecentForCharacter(Uuid $characterId, int $limit): array;

    public function save(Encounter $encounter): void;
}
