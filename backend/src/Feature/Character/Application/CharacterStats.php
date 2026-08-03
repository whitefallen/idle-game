<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Model\DerivedStats;
use App\Feature\Character\Domain\Service\DerivedStatsCalculator;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Feature\Inventory\Domain\Service\EquipmentCalculator;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves a character's derived stats, including equipment.
 *
 * Lives in the Application layer rather than on the entity because equipment
 * belongs to a different aggregate: the character cannot load its own items,
 * and giving it a repository would make the entity depend on persistence.
 *
 * Reading the Inventory feature's repositories is the "published read model"
 * access described in docs/architecture.md section 3.1 — read-only, through a
 * Domain interface, and never mutating another feature's state.
 */
final class CharacterStats
{
    public function __construct(
        private readonly ItemInstanceRepository $items,
        private readonly ItemDefinitionRepository $definitions,
        private readonly AffixRepository $affixes,
    ) {
    }

    public function forCharacter(Character $character): DerivedStats
    {
        return DerivedStatsCalculator::calculate(
            $character->level(),
            $character->attributes(),
            $this->equipmentOf($character->id()),
        );
    }

    public function powerScoreFor(Character $character): int
    {
        return DerivedStatsCalculator::powerScore(
            $character->level(),
            $character->attributes(),
            $this->equipmentOf($character->id()),
        );
    }

    public function equipmentOf(Uuid $characterId): EquipmentBonuses
    {
        return EquipmentCalculator::sum(
            $this->items->findEquippedByCharacter($characterId),
            $this->definitions->all(),
            $this->affixes->all(),
        );
    }
}
