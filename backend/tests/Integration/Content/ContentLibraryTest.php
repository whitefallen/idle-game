<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Encounter\Domain\Repository\EncounterRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CI guard for the real content library.
 *
 * Every content change runs this. It is the difference between discovering a
 * broken drop table in review and discovering it when a player's encounter
 * throws.
 */
final class ContentLibraryTest extends KernelTestCase
{
    /**
     * Exercises the command itself rather than the providers directly, so the
     * path CI runs is the path under test.
     */
    public function testContentValidateCommandSucceeds(): void
    {
        $tester = new CommandTester(
            (new Application(self::bootKernel()))->find('content:validate'),
        );

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('valid', $tester->getDisplay());
    }

    /**
     * The strongest check available: every authored encounter must actually
     * resolve. Schema validity does not imply a fightable encounter — a monster
     * whose plan can stall, or an ability referencing a missing effect, only
     * surfaces when the engine runs.
     */
    public function testEveryEncounterResolves(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EncounterRepository $encounters */
        $encounters = $container->get(EncounterRepository::class);
        /** @var MonsterRepository $monsters */
        $monsters = $container->get(MonsterRepository::class);
        /** @var AbilityRepository $abilities */
        $abilities = $container->get(AbilityRepository::class);
        /** @var EffectRepository $effects */
        $effects = $container->get(EffectRepository::class);

        $engine = new CombatEngine();

        self::assertNotEmpty($encounters->all(), 'Expected authored encounters.');

        foreach ($encounters->all() as $encounterId => $encounter) {
            $participants = [ContentTestCharacter::participant()];

            foreach ($encounter->monsterIds as $index => $monsterId) {
                $participants[] = $monsters->get($monsterId)->toParticipant(
                    sprintf('monster-%02d-%s', $index, $monsterId),
                );
            }

            $input = new CombatInput(
                $participants,
                $abilities->all(),
                $effects->all(),
                CombatEngine::RULESET_VERSION,
            );

            $log = $engine->resolve($input, 20260802);

            self::assertLessThanOrEqual(
                CombatEngine::MAX_ROUNDS,
                $log->rounds,
                sprintf('Encounter "%s" did not terminate.', $encounterId),
            );

            self::assertNotEmpty($log->events, sprintf('Encounter "%s" produced no events.', $encounterId));
        }
    }

    /**
     * Every authored ability must be reachable from the localisation key it
     * declares. A missing key renders as a raw id in the client, which players
     * report as a bug.
     */
    public function testEveryDefinitionDeclaresALocalisationKey(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var AbilityRepository $abilities */
        $abilities = $container->get(AbilityRepository::class);
        /** @var EffectRepository $effects */
        $effects = $container->get(EffectRepository::class);

        foreach ($abilities->all() as $ability) {
            self::assertNotSame('', $ability->localisationKey, $ability->id);
        }

        foreach ($effects->all() as $effect) {
            self::assertNotSame('', $effect->localisationKey, $effect->id);
        }
    }
}
