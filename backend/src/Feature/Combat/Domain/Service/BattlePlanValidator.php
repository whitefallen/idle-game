<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Service;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\ConditionSubject;
use App\Feature\Combat\Domain\Model\PlanIssue;
use App\Feature\Combat\Domain\Model\PlanIssueCode;
use App\Feature\Combat\Domain\Model\PlanRule;
use InvalidArgumentException;

/**
 * Validates a battle plan submitted by a player.
 *
 * Pure: it takes the raw submission plus the abilities in play and returns every
 * problem it found. It never throws for a bad plan, because a bad plan is
 * expected input from an editor — the caller decides what to do with the issues.
 *
 * This is the server-side authority. The client validates too, for immediacy,
 * but that check is a convenience and this one is the rule: a plan arrives from
 * untrusted input and is evaluated inside combat, so nothing may reach the
 * engine that the engine could choke on.
 */
final class BattlePlanValidator
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rules      The submitted plan.
     * @param array<string, Ability>     $abilities  Every ability in the content library.
     * @param list<string>               $known      Ability ids this character has.
     *
     * @return list<PlanIssue>
     */
    public static function validate(array $rules, array $abilities, array $known): array
    {
        if ($rules === []) {
            return [new PlanIssue(PlanIssueCode::PlanEmpty, 'A battle plan needs at least one rule.')];
        }

        if (count($rules) > BattlePlan::MAX_RULES) {
            return [new PlanIssue(
                PlanIssueCode::TooManyRules,
                sprintf('A battle plan may contain at most %d rules.', BattlePlan::MAX_RULES),
            )];
        }

        $issues = [];
        $parsed = [];

        foreach ($rules as $index => $raw) {
            $rule = self::parseRule($raw, $index, $issues);

            if ($rule === null) {
                continue;
            }

            $parsed[$index] = $rule;

            self::checkAbility($rule, $index, $abilities, $known, $issues);
            self::checkCondition($rule->condition, $index, $abilities, $issues);
        }

        // Structural checks need every rule to have parsed. Reporting "your last
        // rule is wrong" when the real problem is a typo in rule 2 would send a
        // player looking in the wrong place.
        if (count($parsed) === count($rules)) {
            self::checkFallback($parsed, $abilities, $issues);
            self::checkReachability($parsed, $abilities, $issues);
        }

        return $issues;
    }

    /**
     * @param list<PlanIssue> $issues
     */
    private static function parseRule(mixed $raw, int $index, array &$issues): ?PlanRule
    {
        if (!is_array($raw)) {
            $issues[] = new PlanIssue(PlanIssueCode::MalformedRule, 'A rule must be an object.', $index);

            return null;
        }

        /** @var array<string, mixed> $raw */
        $condition = $raw['condition'] ?? null;

        if (is_array($condition) && count($condition) > Condition::MAX_TERMS) {
            $issues[] = new PlanIssue(
                PlanIssueCode::TooManyTerms,
                sprintf('A condition may combine at most %d terms.', Condition::MAX_TERMS),
                $index,
            );

            return null;
        }

        if (is_array($condition) && $condition === []) {
            $issues[] = new PlanIssue(
                PlanIssueCode::ConditionEmpty,
                'A condition needs at least one term. Use "always" for an unconditional rule.',
                $index,
            );

            return null;
        }

        try {
            return PlanRule::fromArray($raw);
        } catch (InvalidArgumentException $e) {
            // The value objects reject malformed combinations in their
            // constructors, so this catches everything the grammar forbids
            // without duplicating those rules here.
            $issues[] = new PlanIssue(PlanIssueCode::MalformedTerm, $e->getMessage(), $index);

            return null;
        }
    }

    /**
     * @param array<string, Ability> $abilities
     * @param list<string>           $known
     * @param list<PlanIssue>        $issues
     */
    private static function checkAbility(
        PlanRule $rule,
        int $index,
        array $abilities,
        array $known,
        array &$issues,
    ): void {
        if (!isset($abilities[$rule->abilityId])) {
            $issues[] = new PlanIssue(
                PlanIssueCode::UnknownAbility,
                sprintf('No such ability "%s".', $rule->abilityId),
                $index,
            );

            return;
        }

        if (!in_array($rule->abilityId, $known, true)) {
            $issues[] = new PlanIssue(
                PlanIssueCode::AbilityNotLearned,
                sprintf('This character has not learned "%s".', $rule->abilityId),
                $index,
            );
        }
    }

    /**
     * @param array<string, Ability> $abilities
     * @param list<PlanIssue>        $issues
     */
    private static function checkCondition(
        Condition $condition,
        int $ruleIndex,
        array $abilities,
        array &$issues,
    ): void {
        $effects = self::referableEffectIds($abilities);

        foreach ($condition->terms as $termIndex => $term) {
            if ($term->subject->requiresEffectId() && !in_array((string) $term->effectId, $effects, true)) {
                // Referencing an effect no ability can apply is always a
                // mistake: the condition could never become true.
                $issues[] = new PlanIssue(
                    PlanIssueCode::UnknownEffect,
                    sprintf('No ability applies the effect "%s".', (string) $term->effectId),
                    $ruleIndex,
                    $termIndex,
                );
            }

            $isPercent = $term->subject === ConditionSubject::SelfHealthPercent
                || $term->subject === ConditionSubject::TargetHealthPercent;

            if ($isPercent && ($term->value === null || $term->value < 0 || $term->value > 100)) {
                $issues[] = new PlanIssue(
                    PlanIssueCode::PercentOutOfRange,
                    'A health percentage must be between 0 and 100.',
                    $ruleIndex,
                    $termIndex,
                );
            }
        }
    }

    /**
     * @param array<int, PlanRule>   $rules
     * @param array<string, Ability> $abilities
     * @param list<PlanIssue>        $issues
     */
    private static function checkFallback(array $rules, array $abilities, array &$issues): void
    {
        $lastIndex = array_key_last($rules);

        if ($lastIndex === null) {
            return;
        }

        $last = $rules[$lastIndex];

        if (!$last->condition->isUnconditional()) {
            $issues[] = new PlanIssue(
                PlanIssueCode::FinalRuleMustBeUnconditional,
                'The last rule must be unconditional, so your character always has something to do.',
                $lastIndex,
            );
        }

        $ability = $abilities[$last->abilityId] ?? null;

        if ($ability !== null && !$ability->isAlwaysAvailable()) {
            $issues[] = new PlanIssue(
                PlanIssueCode::FinalRuleMustBeAlwaysAvailable,
                'The last rule needs an ability with no Focus cost and no cooldown, '
                . 'or your character can end up unable to act.',
                $lastIndex,
            );
        }
    }

    /**
     * Flags rules that can never fire because an earlier unconditional rule
     * with an always-available ability will always win.
     *
     * A warning, not an error. It is worth telling a player, but refusing the
     * plan would be presumptuous — and the combat log makes the consequence
     * visible anyway.
     *
     * @param array<int, PlanRule>   $rules
     * @param array<string, Ability> $abilities
     * @param list<PlanIssue>        $issues
     */
    private static function checkReachability(array $rules, array $abilities, array &$issues): void
    {
        $blockedFrom = null;

        foreach ($rules as $index => $rule) {
            if ($blockedFrom !== null) {
                $issues[] = new PlanIssue(
                    PlanIssueCode::UnreachableRule,
                    sprintf('Rule %d always fires first, so this rule can never be used.', $blockedFrom + 1),
                    $index,
                );

                continue;
            }

            $ability = $abilities[$rule->abilityId] ?? null;

            if ($rule->condition->isUnconditional() && $ability !== null && $ability->isAlwaysAvailable()) {
                $blockedFrom = $index;
            }
        }
    }

    /**
     * Effects any ability in the library can apply. A condition may only test
     * for an effect that something is capable of producing.
     *
     * @param array<string, Ability> $abilities
     *
     * @return list<string>
     */
    private static function referableEffectIds(array $abilities): array
    {
        $ids = [];

        foreach ($abilities as $ability) {
            if ($ability->effectId !== null) {
                $ids[$ability->effectId] = true;
            }
        }

        return array_keys($ids);
    }
}
