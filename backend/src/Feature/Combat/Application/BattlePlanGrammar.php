<?php

declare(strict_types=1);

namespace App\Feature\Combat\Application;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\ComparisonOperator;
use App\Feature\Combat\Domain\Model\Condition;
use App\Feature\Combat\Domain\Model\ConditionSubject;
use App\Feature\Combat\Domain\Repository\AbilityRepository;

/**
 * Describes the battle plan grammar for the editor.
 *
 * Published rather than duplicated in the client. The grammar is
 * server-authoritative — it is what the engine evaluates and what the validator
 * enforces — so a client that hardcoded its own copy would drift, and the drift
 * would show up as a plan the editor offers and the server rejects.
 */
final class BattlePlanGrammar
{
    public function __construct(private readonly AbilityRepository $abilities)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'limits' => [
                'max_rules' => BattlePlan::MAX_RULES,
                'max_terms_per_condition' => Condition::MAX_TERMS,
            ],
            'subjects' => array_map(
                static fn (ConditionSubject $subject): array => [
                    'value' => $subject->value,
                    // The editor uses these to decide which inputs to render:
                    // a numeric subject needs an operator and a value, a
                    // presence subject needs an effect, "always" needs neither.
                    'requires_operator' => $subject->isNumeric(),
                    'requires_value' => $subject->isNumeric(),
                    'requires_effect' => $subject->requiresEffectId(),
                    'is_percentage' => in_array($subject, [
                        ConditionSubject::SelfHealthPercent,
                        ConditionSubject::TargetHealthPercent,
                    ], true),
                ],
                ConditionSubject::cases(),
            ),
            'operators' => array_map(
                static fn (ComparisonOperator $operator): string => $operator->value,
                ComparisonOperator::cases(),
            ),
            // Only effects something can actually apply. Offering a condition
            // that could never become true would be a trap.
            'effects' => $this->referableEffectIds(),
        ];
    }

    /**
     * Ability metadata the editor needs.
     *
     * Focus cost and cooldown are included because they explain why a rule fell
     * through in the combat log, and because the last rule must name an ability
     * that is always available — the editor should be able to say which
     * abilities qualify rather than letting a player discover it by rejection.
     *
     * @param list<string> $abilityIds
     *
     * @return list<array<string, mixed>>
     */
    public function describeAbilities(array $abilityIds): array
    {
        $described = [];

        foreach ($abilityIds as $id) {
            if (!$this->abilities->has($id)) {
                continue;
            }

            $ability = $this->abilities->get($id);

            $described[] = [
                'id' => $ability->id,
                'localisation_key' => $ability->localisationKey,
                'focus_cost' => $ability->focusCost,
                'cooldown_rounds' => $ability->cooldownRounds,
                'school' => $ability->school->value,
                'selector' => $ability->selector->value,
                'effect_id' => $ability->effectId,
                'can_be_fallback' => $ability->isAlwaysAvailable(),
            ];
        }

        return $described;
    }

    /**
     * @return list<string>
     */
    private function referableEffectIds(): array
    {
        $ids = [];

        foreach ($this->abilities->all() as $ability) {
            if ($ability->effectId !== null) {
                $ids[$ability->effectId] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids, SORT_STRING);

        return $ids;
    }
}
