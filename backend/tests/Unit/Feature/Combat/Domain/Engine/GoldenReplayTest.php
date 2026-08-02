<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Tests\Unit\Feature\Combat\Support\GoldenScenarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The regression net for combat resolution.
 *
 * Any change to the engine that alters an outcome fails here. That is the
 * intended behaviour, not an obstacle: an intentional balance change is
 * accompanied by a ruleset version bump and regenerated fixtures in the same
 * commit, which makes the change reviewable. An unintentional one is caught
 * before it reaches a player's stored history.
 *
 * Regenerate with: docker compose exec php php bin/regenerate-combat-goldens.php
 */
#[CoversClass(CombatEngine::class)]
final class GoldenReplayTest extends TestCase
{
    private const string FIXTURE_DIR = __DIR__ . '/../../../../../Fixtures/Combat/Golden';

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarioNames(): iterable
    {
        foreach (array_keys(GoldenScenarios::all()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('scenarioNames')]
    public function testReplayMatchesTheStoredGolden(string $name): void
    {
        $path = self::FIXTURE_DIR . '/' . $name . '.json';

        self::assertFileExists($path, sprintf(
            'Missing golden fixture for "%s". Run bin/regenerate-combat-goldens.php.',
            $name,
        ));

        /** @var array{seed: string, log: array<string, mixed>} $expected */
        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $scenario = GoldenScenarios::all()[$name];

        self::assertSame(
            (string) $scenario['seed'],
            $expected['seed'],
            'The fixture was generated with a different seed than the scenario now declares.',
        );

        $actual = (new CombatEngine())->resolve($scenario['input'], $scenario['seed'])->toArray();

        self::assertSame(
            $expected['log'],
            $actual,
            sprintf(
                'Resolution of "%s" changed. If this was intentional, bump %s::RULESET_VERSION '
                . 'and regenerate the corpus in the same commit.',
                $name,
                CombatEngine::class,
            ),
        );
    }

    /**
     * The corpus is only a regression net if it covers the ruleset it claims to.
     */
    #[DataProvider('scenarioNames')]
    public function testGoldenWasGeneratedByTheCurrentRuleset(string $name): void
    {
        /** @var array{log: array{rulesetVersion: string}} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(self::FIXTURE_DIR . '/' . $name . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            CombatEngine::RULESET_VERSION,
            $fixture['log']['rulesetVersion'],
            'A stale golden fixture proves nothing about the current ruleset.',
        );
    }
}
