<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Service;

use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\PlanRule;

/**
 * What a new character begins with.
 *
 * A new character must be able to play the early game without ever opening the
 * battle plan editor, so the default plan is sensible on its own: complexity is
 * opt-in. See docs/game-bible.md section 5.
 *
 * The default also demonstrates the mechanic's central behaviour — the stronger
 * ability is attempted first and falls through to the basic attack whenever it
 * is on cooldown or unaffordable — which is what the tutorial later builds on.
 */
final class StartingLoadout
{
    public const string FALLBACK_ABILITY = 'ability.measured_strike';

    private const string OPENING_ABILITY = 'ability.rupture';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function abilityIds(): array
    {
        return [self::FALLBACK_ABILITY, self::OPENING_ABILITY];
    }

    public static function battlePlan(): BattlePlan
    {
        return new BattlePlan([
            new PlanRule(Condition::always(), self::OPENING_ABILITY),
            new PlanRule(Condition::always(), self::FALLBACK_ABILITY),
        ]);
    }
}
