<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ties the balance tolerances to a character the game can actually create.
 *
 * {@see EncounterBalanceTest} declares every win rate against
 * {@see CanonicalBuild}, which slots abilities up to the level's loadout
 * capacity. That is only a meaningful statement if the game grants those
 * abilities by that level — otherwise the tolerances describe a character no
 * player can build, and CI stays green on a promise.
 *
 * That is not hypothetical. Before disciplines existed, a character's abilities
 * were fixed at creation and never grew, so a real level 20 warden had two
 * abilities and no heal while the fixture assumed five. Measured against the
 * real character, the stretch 3 tolerances were out by a wide margin — the
 * Warden of Ash sat at 1.0% against a declared floor of 45%. These tests exist
 * so that gap cannot silently reopen.
 */
final class DisciplineAvailabilityTest extends KernelTestCase
{
    /**
     * The load-bearing assertion: everything the canonical build slots at a
     * level must be unlocked by that level.
     */
    public function testEveryAbilityTheCanonicalBuildSlotsIsUnlockedByThatLevel(): void
    {
        /** @var DisciplineRepository $disciplines */
        $disciplines = static::getContainer()->get(DisciplineRepository::class);

        foreach (self::levelsUnderTest() as $level) {
            $granted = $disciplines->grantedAbilityIdsAtLevel($level);

            foreach (CanonicalBuild::abilitiesAt($level) as $abilityId) {
                self::assertContains($abilityId, $granted, sprintf(
                    'The canonical build slots "%s" at level %d, but no discipline grants it by then. '
                    . 'Either the balance tolerances describe an unreachable character, or the discipline '
                    . 'unlock level has drifted past the content tuned against it.',
                    $abilityId,
                    $level,
                ));
            }
        }
    }

    /**
     * A loadout is only legal if it fits. The fixture derives its size from
     * ProgressionRules, so this guards against the two drifting apart.
     */
    public function testTheCanonicalLoadoutFitsInTheAvailableSlots(): void
    {
        foreach (self::levelsUnderTest() as $level) {
            self::assertLessThanOrEqual(
                ProgressionRules::loadoutSlotsAt($level),
                count(CanonicalBuild::abilitiesAt($level)),
                sprintf('The canonical build overfills its loadout at level %d.', $level),
            );
        }
    }

    /**
     * Every canonical loadout must contain a legal fallback, because a battle
     * plan's final rule has to be unconditional and always usable. A build that
     * cannot express a valid plan is not a build.
     */
    public function testEveryCanonicalLoadoutCanFormAValidPlan(): void
    {
        /** @var AbilityRepository $abilities */
        $abilities = static::getContainer()->get(AbilityRepository::class);

        foreach (self::levelsUnderTest() as $level) {
            $fallbacks = array_filter(
                CanonicalBuild::abilitiesAt($level),
                static fn (string $id): bool => $abilities->get($id)->isAlwaysAvailable(),
            );

            self::assertNotEmpty($fallbacks, sprintf(
                'No ability in the level %d canonical loadout is free and off cooldown, '
                . 'so the plan could never resolve to an action.',
                $level,
            ));
        }
    }

    /**
     * The catalogue must keep pace with the content. Every stretch gated at a
     * level should have at least one new answer available by the time a player
     * arrives, or the difficulty step is a wall rather than a lesson.
     */
    public function testEveryEncounterGateHasAnUnlockedAbilityBudget(): void
    {
        $container = static::getContainer();

        /** @var DisciplineRepository $disciplines */
        $disciplines = $container->get(DisciplineRepository::class);
        /** @var EncounterDefinitionRepository $encounters */
        $encounters = $container->get(EncounterDefinitionRepository::class);

        foreach ($encounters->all() as $encounterId => $encounter) {
            $granted = count($disciplines->grantedAbilityIdsAtLevel($encounter->requiredLevel));
            $slots = ProgressionRules::loadoutSlotsAt($encounter->requiredLevel);

            self::assertGreaterThanOrEqual($slots, $granted, sprintf(
                'At level %d, the gate for "%s", a character has %d loadout slot(s) but only %d unlocked '
                . 'abilit(ies) to fill them with. Slots outpacing the catalogue means the loadout stops '
                . 'being a choice.',
                $encounter->requiredLevel,
                (string) $encounterId,
                $slots,
                $granted,
            ));
        }
    }

    /**
     * Levels the balance suite actually declares tolerances at, plus the gates
     * of every authored encounter.
     *
     * @return list<int>
     */
    private static function levelsUnderTest(): array
    {
        $levels = [1, 4, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17, 18, 20, 22, 24];

        sort($levels);

        return $levels;
    }
}
