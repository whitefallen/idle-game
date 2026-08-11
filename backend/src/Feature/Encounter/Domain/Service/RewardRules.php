<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Service;

use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Combat\Domain\Rng\Int64;
use App\Feature\Combat\Domain\Rng\SplitMix64;
use App\Feature\Encounter\Domain\Model\EncounterDefinition;

/**
 * What an encounter pays out.
 *
 * The gold roll is derived from the encounter's seed rather than from fresh
 * randomness, so a stored encounter reproduces its rewards exactly. An
 * investigation into "why does this account have so much gold" can then replay
 * the ledger from the same data that replays the fights, instead of having to
 * trust a separately recorded number.
 *
 * Encounter gold must remain the dominant faucet: if passive income ever
 * rivals it, the Vigor cap stops constraining income and the playstyle parity
 * contract breaks. See docs/economy.md section 2.
 */
final class RewardRules
{
    /**
     * Distinguishes the gold draw from any other use of the same seed, so a
     * future reward roll cannot accidentally correlate with this one.
     */
    private const int GOLD_STREAM = 0x601D5EED;

    private function __construct()
    {
    }

    /**
     * Whether an outcome earns experience, gold and loot at all.
     *
     * Only a Victory does. A Defeat or a Draw earns nothing — see
     * game-bible.md section 9's "cost without completion" rule.
     */
    public static function isPayable(Outcome $outcome): bool
    {
        return $outcome === Outcome::Victory;
    }

    /**
     * How much of a spent Vigor cost comes back.
     *
     * Only a Draw refunds — it means the round cap was reached, a design
     * failure rather than a player one. A Defeat keeps the cost spent, same as
     * a Victory. See docs/combat.md section 4.
     */
    public static function vigorRefund(Outcome $outcome, int $vigorCost): int
    {
        return $outcome === Outcome::Draw ? $vigorCost : 0;
    }

    public static function experience(EncounterDefinition $encounter, int $characterLevel): int
    {
        return ProgressionRules::awardedExperience(
            $encounter->baseExperience(),
            $characterLevel,
            $encounter->level,
        );
    }

    /**
     * Base gold plus a deterministic variance of up to twice the encounter level.
     */
    public static function gold(EncounterDefinition $encounter, int $seed): int
    {
        $spread = 2 * $encounter->level + 1;
        $roll = SplitMix64::mix(Int64::add($seed, self::GOLD_STREAM));

        // Take the top 31 bits so the value is non-negative before the modulo.
        $variance = (Int64::unsignedShiftRight($roll, 33) & 0x7FFFFFFF) % $spread;

        return 5 * $encounter->level + $variance;
    }
}
