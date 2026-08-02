<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * What a battle plan condition inspects.
 *
 * The grammar is closed and non-Turing-complete by design. A general scripting
 * language here would be a security problem (untrusted code executing on the
 * server), a performance problem (unbounded evaluation inside the request path)
 * and a support problem. See docs/combat.md section 5.1.
 */
enum ConditionSubject: string
{
    /** Always true. Only valid as the sole term of a condition. */
    case Always = 'always';

    /** Percentage of the acting participant's maximum health, 0-100. */
    case SelfHealthPercent = 'self.health_percent';

    /** The acting participant's current Focus, in points. */
    case SelfFocus = 'self.focus';

    /** Number of living opponents. */
    case EnemyCount = 'enemy.count';

    /** Percentage health of the ability's prospective primary target, 0-100. */
    case TargetHealthPercent = 'target.health_percent';

    /** The current round, 1-based. */
    case Round = 'round';

    case SelfHasEffect = 'self.has_effect';

    case TargetHasEffect = 'target.has_effect';

    /**
     * Whether the subject is compared numerically, as opposed to being a
     * presence check or the unconditional case.
     */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::SelfHealthPercent,
            self::SelfFocus,
            self::EnemyCount,
            self::TargetHealthPercent,
            self::Round => true,
            default => false,
        };
    }

    public function requiresEffectId(): bool
    {
        return $this === self::SelfHasEffect || $this === self::TargetHasEffect;
    }

    /**
     * Whether evaluating this subject needs a prospective target resolved
     * first. Used by the plan evaluator to avoid resolving targets for rules
     * that cannot need them.
     */
    public function requiresTarget(): bool
    {
        return $this === self::TargetHealthPercent || $this === self::TargetHasEffect;
    }
}
