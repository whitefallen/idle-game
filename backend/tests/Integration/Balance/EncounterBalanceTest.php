<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Feature\Character\Domain\Model\Attributes;
use App\Feature\Character\Domain\Service\DerivedStatsCalculator;
use App\Feature\Character\Domain\Service\StartingLoadout;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Balance regression guard.
 *
 * Every number in docs/progression.md is a guess until it is simulated, and
 * balance regressions must be caught by CI rather than by players. This is the
 * simulator described in docs/progression.md section 6, reduced to the
 * assertions that matter: a canonical build is run against each encounter and
 * the win rate must fall inside a declared tolerance.
 *
 * A failure here is not necessarily a bug. It means a content or formula change
 * moved the difficulty curve, and the change and the tolerance must be
 * reconciled deliberately rather than discovered in production.
 */
final class EncounterBalanceTest extends KernelTestCase
{
    private const int SAMPLES = 300;

    /**
     * Canonical builds: what a player actually has at each level, assuming they
     * spend their points on survivability and their weapon attribute.
     */
    private static function buildFor(int $level): Attributes
    {
        return match ($level) {
            1 => Attributes::starting(),
            4 => Attributes::of(9, 5, 5, 11, 5),
            6 => Attributes::of(12, 6, 5, 15, 5),
            default => self::fail('No canonical build defined for level ' . $level),
        };
    }

    /**
     * @return iterable<string, array{string, int, float, float}>
     */
    public static function tolerances(): iterable
    {
        // The first fight a new player ever has. Losing it teaches nothing and
        // reads as the game being broken, so the floor is deliberately severe.
        yield 'first patrol is a guaranteed win at level 1' => ['encounter.stretch1.patrol', 1, 0.99, 1.0];

        // Gated at level 4. A genuine challenge at the gate, routine two levels
        // later — progression should be felt as learning, not as a wall.
        yield 'swarm is a real challenge at its gate' => ['encounter.stretch1.swarm', 4, 0.30, 0.75];
        yield 'swarm is routine by level 6' => ['encounter.stretch1.swarm', 6, 0.90, 1.0];

        yield 'elite is a real challenge at its gate' => ['encounter.stretch1.stalker', 4, 0.45, 0.85];
        yield 'elite is routine by level 6' => ['encounter.stretch1.stalker', 6, 0.90, 1.0];
    }

    #[DataProvider('tolerances')]
    public function testWinRateIsWithinTolerance(
        string $encounterId,
        int $level,
        float $minimum,
        float $maximum,
    ): void {
        $result = $this->simulate($encounterId, $level);

        self::assertGreaterThanOrEqual($minimum, $result['winRate'], sprintf(
            '%s at level %d wins %.1f%% of the time, below the %.0f%% floor.',
            $encounterId,
            $level,
            100 * $result['winRate'],
            100 * $minimum,
        ));

        self::assertLessThanOrEqual($maximum, $result['winRate'], sprintf(
            '%s at level %d wins %.1f%% of the time, above the %.0f%% ceiling.',
            $encounterId,
            $level,
            100 * $result['winRate'],
            100 * $maximum,
        ));
    }

    /**
     * A draw means the round cap was reached, which the design treats as a
     * failure of the encounter rather than of the player. No authored content
     * should be able to produce one.
     */
    public function testNoAuthoredEncounterCanEndInADraw(): void
    {
        /** @var EncounterDefinitionRepository $definitions */
        $definitions = static::getContainer()->get(EncounterDefinitionRepository::class);

        foreach (array_keys($definitions->all()) as $encounterId) {
            foreach ([1, 4, 6] as $level) {
                self::assertSame(
                    0,
                    $this->simulate((string) $encounterId, $level)['draws'],
                    sprintf('%s reached the round cap at level %d.', (string) $encounterId, $level),
                );
            }
        }
    }

    /**
     * @return array{winRate: float, draws: int, averageRounds: float}
     */
    private function simulate(string $encounterId, int $level): array
    {
        $container = static::getContainer();

        /** @var EncounterDefinitionRepository $definitions */
        $definitions = $container->get(EncounterDefinitionRepository::class);
        /** @var MonsterRepository $monsters */
        $monsters = $container->get(MonsterRepository::class);
        /** @var AbilityRepository $abilities */
        $abilities = $container->get(AbilityRepository::class);
        /** @var EffectRepository $effects */
        $effects = $container->get(EffectRepository::class);

        $definition = $definitions->get($encounterId);
        $engine = new CombatEngine();

        $wins = 0;
        $draws = 0;
        $rounds = 0;

        for ($sample = 0; $sample < self::SAMPLES; ++$sample) {
            $participants = [$this->character($level)];

            foreach ($definition->monsterIds as $index => $monsterId) {
                $participants[] = $monsters->get($monsterId)->toParticipant(
                    sprintf('monster-%02d-%s', $index, $monsterId),
                );
            }

            $log = $engine->resolve(
                new CombatInput($participants, $abilities->all(), $effects->all(), CombatEngine::RULESET_VERSION),
                // A fixed, well-spread seed sequence: the suite must be
                // reproducible, so a failure is always investigable.
                $sample * 2_654_435_761,
            );

            $wins += $log->outcome === Outcome::Victory ? 1 : 0;
            $draws += $log->outcome === Outcome::Draw ? 1 : 0;
            $rounds += $log->rounds;
        }

        return [
            'winRate' => $wins / self::SAMPLES,
            'draws' => $draws,
            'averageRounds' => $rounds / self::SAMPLES,
        ];
    }

    private function character(int $level): Participant
    {
        $attributes = self::buildFor($level);
        $stats = DerivedStatsCalculator::calculate($level, $attributes);

        return new Participant(
            id: 'aaaa-character',
            definitionId: 'character',
            name: 'Canonical Warden',
            team: Team::Players,
            level: $level,
            maxHealth: $stats->maxHealth,
            initiative: $stats->initiative,
            maxFocus: $stats->maxFocus,
            focusPerTurn: $stats->focusPerTurn,
            weaponBaseDamage: $stats->weaponBaseDamage,
            flatDamageBonus: $stats->flatDamageBonus,
            scalingBp: $stats->scalingBp,
            critChanceBp: $stats->critChanceBp,
            critPowerBp: $stats->critPowerBp,
            dodgeChanceBp: $stats->dodgeChanceBp,
            accuracyBp: $stats->accuracyBp,
            armourRating: $stats->armourRating,
            resistanceRatings: $stats->resistanceRatings,
            battlePlan: StartingLoadout::battlePlan(),
            abilityIds: StartingLoadout::abilityIds(),
        );
    }
}
