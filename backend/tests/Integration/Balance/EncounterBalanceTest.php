<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Feature\Character\Domain\Service\DerivedStatsCalculator;
use App\Feature\Combat\Domain\Engine\CombatEngine;
use App\Feature\Combat\Domain\Model\CombatInput;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Feature\Encounter\Domain\Model\EncounterDefinition;
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
 * assertions that matter: {@see CanonicalBuild} is run against each encounter
 * and the win rate must fall inside a declared tolerance.
 *
 * A failure here is not necessarily a bug. It means a content or formula change
 * moved the difficulty curve, and the change and the tolerance must be
 * reconciled deliberately rather than discovered in production.
 */
final class EncounterBalanceTest extends KernelTestCase
{
    private const int SAMPLES = 300;

    /**
     * The shape every tier is tuned to, and the reason the tolerances below
     * look the way they do:
     *
     *  - a **patrol** at its gate is close to certain — it is the content a
     *    player farms, and losing a farm run to variance teaches nothing;
     *  - an **elite** at its gate is a genuine coin-flip-to-likely win, and
     *    routine two levels later, so progression is felt as learning rather
     *    than as a wall (docs/game-bible.md section 4.2);
     *  - a **boss** at its gate sits with the elites but takes far longer, so
     *    the loss is felt as a fight that went wrong rather than a fight that
     *    was never winnable.
     *
     * @return iterable<string, array{string, int, float, float}>
     */
    public static function tolerances(): iterable
    {
        // ------------------------------------------------------ stretch 1
        // The first fight a new player ever has. Losing it teaches nothing and
        // reads as the game being broken, so the floor is deliberately severe.
        yield 'first patrol is a guaranteed win at level 1' => ['encounter.stretch1.patrol', 1, 0.99, 1.0];

        // Gated at level 4. A genuine challenge at the gate, routine two levels
        // later — progression should be felt as learning, not as a wall.
        yield 'swarm is a real challenge at its gate' => ['encounter.stretch1.swarm', 4, 0.30, 0.75];
        yield 'swarm is routine by level 6' => ['encounter.stretch1.swarm', 6, 0.90, 1.0];

        yield 'elite is a real challenge at its gate' => ['encounter.stretch1.stalker', 4, 0.45, 0.85];
        yield 'elite is routine by level 6' => ['encounter.stretch1.stalker', 6, 0.90, 1.0];

        // ------------------------------------------------------ stretch 2
        // Patrols. The causeway is the stretch's opening fight and is meant to
        // read as safe; the chanter and lurker patrols are where the sustain
        // mechanic is introduced without being able to punish it.
        yield 'causeway patrol is safe at its gate' => ['encounter.stretch2.causeway', 7, 0.95, 1.0];
        yield 'chanter patrol is safe at its gate' => ['encounter.stretch2.chanters', 8, 0.92, 1.0];
        yield 'lurker patrol is safe at its gate' => ['encounter.stretch2.lurkers', 9, 0.90, 1.0];

        // Elites. The warren is the softer of the two on purpose: it is the
        // first elite of the stretch and the one that teaches the lesson, so
        // the ravager is where the lesson is examined.
        yield 'warren is demanding at its gate' => ['encounter.stretch2.warren', 10, 0.60, 0.92];
        yield 'warren is routine by level 12' => ['encounter.stretch2.warren', 12, 0.92, 1.0];

        yield 'ravager is a coin flip at its gate' => ['encounter.stretch2.ravager', 11, 0.35, 0.65];
        yield 'ravager is routine by level 13' => ['encounter.stretch2.ravager', 13, 0.92, 1.0];

        // ---------------------------------------------------- stretch 2.5
        // The Drowned Span. Its step is the clock, so the elite's tolerance is
        // a statement about pace rather than about power: the herald's buff
        // never lapses once it starts, and the only reason a level 13 warden
        // wins is that they got there first.
        yield 'the Span is safe at its gate' => ['encounter.stretch2_5.span', 12, 0.95, 1.0];

        yield 'the herald is demanding at its gate' => ['encounter.stretch2_5.herald', 13, 0.60, 0.92];
        yield 'the herald is routine by level 15' => ['encounter.stretch2_5.herald', 15, 0.92, 1.0];

        // ------------------------------------------------------ stretch 3
        yield 'vault watch is safe at its gate' => ['encounter.stretch3.vault_watch', 15, 0.95, 1.0];
        yield 'revenant patrol is safe at its gate' => ['encounter.stretch3.revenants', 17, 0.92, 1.0];

        yield 'sentinels are a real challenge at their gate' => ['encounter.stretch3.sentinels', 18, 0.45, 0.80];
        yield 'sentinels are routine by level 20' => ['encounter.stretch3.sentinels', 20, 0.92, 1.0];

        // The first boss. Winnable at the gate by a player who brought the
        // right plan, and comfortably beaten two levels later — a boss that
        // stays unbeatable is a wall, and a boss beaten on the first attempt by
        // everyone is not a boss.
        yield 'the Warden of Ash is winnable at its gate' => ['encounter.stretch3.warden_of_ash', 20, 0.45, 0.78];
        yield 'the Warden of Ash is beaten by level 22' => ['encounter.stretch3.warden_of_ash', 22, 0.90, 1.0];

        // ------------------------------------------------------ stretch 4
        // The Cinder Reach. Both patrols are near-certain, and deliberately so:
        // they are where the player is *shown* the mark, and a teaching fight
        // that can kill you teaches the wrong thing.
        yield 'reach watch is safe at its gate' => ['encounter.stretch4.reach_watch', 22, 0.95, 1.0];
        yield 'the slag line is safe at its gate' => ['encounter.stretch4.slag_line', 24, 0.95, 1.0];

        // The elite is where the mark is examined, and the sharpest tolerance
        // in the suite. That sharpness is the mechanic: an execution either
        // lands on a healthy warden or on a dying one, so two levels of health
        // move the win rate much further here than in an attrition fight.
        yield 'the execution is a real challenge at its gate' => ['encounter.stretch4.execution', 26, 0.45, 0.85];
        yield 'the execution is routine by level 28' => ['encounter.stretch4.execution', 28, 0.92, 1.0];

        // The second boss, held to the same shape as the first.
        yield 'the Cinder Sovereign is winnable at its gate' => ['encounter.stretch4.cinder_sovereign', 28, 0.45, 0.78];
        yield 'the Cinder Sovereign is beaten by level 30' => ['encounter.stretch4.cinder_sovereign', 30, 0.90, 1.0];
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
     * Reaching the round cap is the one outcome the design calls a failure of
     * the encounter rather than of the player: no rewards, Vigor refunded, and
     * nothing the player could have planned differently. No authored content
     * may be able to produce it.
     *
     * Note that this is deliberately narrower than "no draws". A draw is also
     * emitted when both sides die in the same round — two damage-over-time
     * ticks landing fatally at once, say — and that is a legitimate, fully
     * reproducible fight result rather than a balance defect. See
     * docs/combat.md section 4.
     *
     * Each encounter is checked from its own gate upward, because requiredLevel
     * is server-enforced: a fight below the gate is not a state the game can
     * reach, so tuning for it would constrain content for no player's benefit.
     */
    public function testNoAuthoredEncounterCanReachTheRoundCap(): void
    {
        /** @var EncounterDefinitionRepository $definitions */
        $definitions = static::getContainer()->get(EncounterDefinitionRepository::class);

        foreach ($definitions->all() as $encounterId => $definition) {
            foreach (self::levelsToCheck($definition) as $level) {
                self::assertSame(0, $this->simulate((string) $encounterId, $level)['capped'], sprintf(
                    '%s reached the %d-round cap at level %d.',
                    (string) $encounterId,
                    CombatEngine::MAX_ROUNDS,
                    $level,
                ));
            }
        }
    }

    /**
     * The gate, the level content becomes routine at, and a level far above it
     * where an over-levelled player's sustain could in principle out-heal a
     * high-armour enemy's chip damage forever.
     *
     * @return list<int>
     */
    private static function levelsToCheck(EncounterDefinition $definition): array
    {
        $gate = $definition->requiredLevel;

        return array_values(array_filter(
            [$gate, $gate + 2, $gate + 4, 40],
            static fn (int $level): bool => $level <= 60,
        ));
    }

    /**
     * @return array{winRate: float, capped: int, averageRounds: float}
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
        $capped = 0;
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
            $capped += $log->rounds >= CombatEngine::MAX_ROUNDS ? 1 : 0;
            $rounds += $log->rounds;
        }

        return [
            'winRate' => $wins / self::SAMPLES,
            'capped' => $capped,
            'averageRounds' => $rounds / self::SAMPLES,
        ];
    }

    private function character(int $level): Participant
    {
        $stats = DerivedStatsCalculator::calculate(
            $level,
            CanonicalBuild::attributesAt($level),
            CanonicalBuild::equipmentAt($level),
        );

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
            battlePlan: CanonicalBuild::planAt($level),
            abilityIds: CanonicalBuild::abilitiesAt($level),
        );
    }
}
