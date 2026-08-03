<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * Stable codes for battle plan validation failures.
 *
 * Persisted nowhere, but part of the API contract: the client branches on these
 * and resolves its own wording, so they are renamed only with a version bump.
 */
enum PlanIssueCode: string
{
    case PlanEmpty = 'PLAN_EMPTY';
    case TooManyRules = 'PLAN_TOO_MANY_RULES';
    case MalformedRule = 'RULE_MALFORMED';
    case UnknownAbility = 'RULE_UNKNOWN_ABILITY';
    case AbilityNotLearned = 'RULE_ABILITY_NOT_LEARNED';

    /**
     * The last rule must be unconditional, or a plan can reach a state where no
     * rule matches and the character does nothing.
     */
    case FinalRuleMustBeUnconditional = 'PLAN_FINAL_RULE_NOT_UNCONDITIONAL';

    /**
     * The last rule's ability must be free and off cooldown always. A fallback
     * that can itself be unavailable is not a fallback.
     */
    case FinalRuleMustBeAlwaysAvailable = 'PLAN_FINAL_RULE_NOT_ALWAYS_AVAILABLE';

    case ConditionEmpty = 'CONDITION_EMPTY';
    case TooManyTerms = 'CONDITION_TOO_MANY_TERMS';
    case MalformedTerm = 'TERM_MALFORMED';
    case UnknownEffect = 'TERM_UNKNOWN_EFFECT';
    case PercentOutOfRange = 'TERM_PERCENT_OUT_OF_RANGE';

    /**
     * A rule after an unconditional one whose ability is always available can
     * never fire. Reported as a warning rather than an error: it is a mistake
     * worth surfacing, not a reason to refuse the plan.
     */
    case UnreachableRule = 'RULE_UNREACHABLE';

    /**
     * Whether this issue prevents the plan being saved. Warnings are returned
     * alongside a successful save so the editor can flag them without blocking
     * a player who knows what they are doing.
     */
    public function isBlocking(): bool
    {
        return $this !== self::UnreachableRule;
    }
}
