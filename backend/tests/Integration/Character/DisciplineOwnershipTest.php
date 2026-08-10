<?php

declare(strict_types=1);

namespace App\Tests\Integration\Character;

use App\Feature\Character\Domain\Repository\DisciplineRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `DisciplineRepository::availableAtLevel()` / `grantedAbilityIdsAtLevel()`
 * return the union of the level-derived set and an explicit `$ownedIds` list
 * — see docs/dungeons.md section 3. This is the one place that union is
 * exercised directly; {@see \App\Tests\Functional\Dungeon\DungeonFlowTest}
 * covers it end to end through the API instead.
 */
final class DisciplineOwnershipTest extends KernelTestCase
{
    /** Gated behind level 4 in content/disciplines/core.yaml. */
    private const string LEVEL_GATED = 'discipline.sweeping_arc';

    public function testALevelGatedDisciplineIsUnavailableBelowItsLevelWithoutOwnership(): void
    {
        $disciplines = $this->disciplines();

        self::assertArrayNotHasKey(self::LEVEL_GATED, $disciplines->availableAtLevel(1));
    }

    public function testOwningAGatedDisciplineMakesItAvailableRegardlessOfLevel(): void
    {
        $disciplines = $this->disciplines();

        self::assertArrayHasKey(
            self::LEVEL_GATED,
            $disciplines->availableAtLevel(1, [self::LEVEL_GATED]),
            'An owned discipline must be available even to a level 1 character.',
        );
    }

    public function testOwnershipOfAnUnrelatedDisciplineDoesNotUnlockOthers(): void
    {
        $disciplines = $this->disciplines();

        self::assertArrayNotHasKey(
            self::LEVEL_GATED,
            $disciplines->availableAtLevel(1, ['discipline.some_other_id']),
        );
    }

    public function testGrantedAbilityIdsAtLevelIncludesOwnedDisciplinesAbility(): void
    {
        $disciplines = $this->disciplines();
        $gated = $disciplines->get(self::LEVEL_GATED);

        self::assertNotContains($gated->abilityId, $disciplines->grantedAbilityIdsAtLevel(1));
        self::assertContains($gated->abilityId, $disciplines->grantedAbilityIdsAtLevel(1, [self::LEVEL_GATED]));
    }

    private function disciplines(): DisciplineRepository
    {
        /** @var DisciplineRepository $disciplines */
        $disciplines = static::getContainer()->get(DisciplineRepository::class);

        return $disciplines;
    }
}
