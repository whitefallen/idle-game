<?php

declare(strict_types=1);

/**
 * Regenerates the golden combat replay corpus.
 *
 * Run this only when a change to resolution is intentional, and commit the
 * regenerated fixtures together with the ruleset version bump that justifies
 * them. That coupling is the point: it makes every balance change visible in
 * review rather than silently altering stored history.
 *
 *   docker compose exec php php bin/regenerate-combat-goldens.php
 *
 * See docs/combat.md section 8.
 */

use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Tests\Unit\Feature\Combat\Support\GoldenScenarios;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(GoldenScenarios::class)) {
    fwrite(STDERR, "Dev dependencies are not installed; run composer install first.\n");

    exit(1);
}

$outputDirectory = dirname(__DIR__) . '/tests/Fixtures/Combat/Golden';

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, sprintf("Could not create %s\n", $outputDirectory));

    exit(1);
}

$engine = new CombatEngine();
$written = 0;

foreach (GoldenScenarios::all() as $name => $scenario) {
    $log = $engine->resolve($scenario['input'], $scenario['seed']);

    $payload = json_encode(
        ['seed' => (string) $scenario['seed'], 'log' => $log->toArray()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    file_put_contents($outputDirectory . '/' . $name . '.json', $payload . "\n");

    printf("%-18s %-8s %2d rounds, %3d events\n", $name, $log->outcome->value, $log->rounds, count($log->events));
    ++$written;
}

printf("\nWrote %d golden replays for ruleset %s.\n", $written, CombatEngine::RULESET_VERSION);
