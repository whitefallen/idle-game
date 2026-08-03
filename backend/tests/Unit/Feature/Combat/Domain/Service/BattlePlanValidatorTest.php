<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Service;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\PlanIssue;
use App\Feature\Combat\Domain\Model\PlanIssueCode;
use App\Feature\Combat\Domain\Model\TargetSelector;
use App\Feature\Combat\Domain\Service\BattlePlanValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The server-side authority on what a battle plan may be.
 *
 * Plans arrive entirely from untrusted client input and are then evaluated
 * inside combat, so anything this lets through is something the engine has to
 * survive.
 */
#[CoversClass(BattlePlanValidator::class)]
final class BattlePlanValidatorTest extends TestCase
{
    /**
     * @return array<string, Ability>
     */
    private static function abilities(): array
    {
        $strike = new Ability(
            id: 'ability.strike',
            localisationKey: 'ability.strike',
            focusCost: 0,
            cooldownRounds: 0,
            damageCoefficientBp: 10000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
        );

        $costly = new Ability(
            id: 'ability.costly',
            localisationKey: 'ability.costly',
            focusCost: 12,
            cooldownRounds: 3,
            damageCoefficientBp: 20000,
            healCoefficientBp: 0,
            school: DamageSchool::Physical,
            selector: TargetSelector::LowestHealthEnemy,
            effectId: 'effect.bleeding',
            effectChanceBp: 7500,
        );

        return ['ability.strike' => $strike, 'ability.costly' => $costly];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<string>|null          $known
     *
     * @return list<PlanIssue>
     */
    private static function validate(array $rules, ?array $known = null): array
    {
        return BattlePlanValidator::validate(
            $rules,
            self::abilities(),
            $known ?? ['ability.strike', 'ability.costly'],
        );
    }

    /**
     * @param list<PlanIssue> $issues
     *
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_map(static fn (PlanIssue $issue): string => $issue->code->value, $issues);
    }

    /**
     * @return array{condition: list<array<string, mixed>>, ability: string}
     */
    private static function always(string $abilityId): array
    {
        return ['condition' => [['subject' => 'always']], 'ability' => $abilityId];
    }

    public function testAcceptsAValidPlan(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'self.health_percent', 'operator' => 'lt', 'value' => 40]], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertSame([], self::codes($issues));
    }

    public function testRejectsAnEmptyPlan(): void
    {
        self::assertSame(['PLAN_EMPTY'], self::codes(self::validate([])));
    }

    public function testRejectsTooManyRules(): void
    {
        $rules = array_fill(0, BattlePlan::MAX_RULES + 1, self::always('ability.strike'));

        self::assertSame(['PLAN_TOO_MANY_RULES'], self::codes(self::validate($rules)));
    }

    public function testRejectsAnUnknownAbility(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'round', 'operator' => 'gte', 'value' => 1]], 'ability' => 'ability.invented'],
            self::always('ability.strike'),
        ]);

        self::assertContains('RULE_UNKNOWN_ABILITY', self::codes($issues));
        self::assertSame(0, $issues[0]->ruleIndex, 'The issue must point at the offending rule.');
    }

    /**
     * A real ability the character has not learned. Without this check a player
     * could grant themselves any ability in the content library by editing
     * their plan.
     */
    public function testRejectsAnAbilityTheCharacterHasNotLearned(): void
    {
        $issues = self::validate(
            [
                ['condition' => [['subject' => 'round', 'operator' => 'gte', 'value' => 1]], 'ability' => 'ability.costly'],
                self::always('ability.strike'),
            ],
            known: ['ability.strike'],
        );

        self::assertContains('RULE_ABILITY_NOT_LEARNED', self::codes($issues));
    }

    public function testRequiresAnUnconditionalFinalRule(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'round', 'operator' => 'gte', 'value' => 2]], 'ability' => 'ability.strike'],
        ]);

        self::assertContains('PLAN_FINAL_RULE_NOT_UNCONDITIONAL', self::codes($issues));
    }

    /**
     * A fallback that can itself be on cooldown or unaffordable is not a
     * fallback — the character would have nothing to do.
     */
    public function testRequiresTheFinalAbilityToBeAlwaysAvailable(): void
    {
        $issues = self::validate([self::always('ability.costly')]);

        self::assertContains('PLAN_FINAL_RULE_NOT_ALWAYS_AVAILABLE', self::codes($issues));
    }

    public function testRejectsTooManyTermsInOneCondition(): void
    {
        $issues = self::validate([
            [
                'condition' => [
                    ['subject' => 'round', 'operator' => 'gte', 'value' => 1],
                    ['subject' => 'self.focus', 'operator' => 'gte', 'value' => 10],
                    ['subject' => 'enemy.count', 'operator' => 'gte', 'value' => 1],
                    ['subject' => 'self.health_percent', 'operator' => 'lt', 'value' => 50],
                ],
                'ability' => 'ability.costly',
            ],
            self::always('ability.strike'),
        ]);

        self::assertContains('CONDITION_TOO_MANY_TERMS', self::codes($issues));
    }

    public function testRejectsAnEmptyCondition(): void
    {
        $issues = self::validate([
            ['condition' => [], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertContains('CONDITION_EMPTY', self::codes($issues));
    }

    /**
     * A condition testing for an effect nothing can apply could never become
     * true, so the rule would silently never fire.
     */
    public function testRejectsAnEffectNoAbilityCanApply(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'target.has_effect', 'effectId' => 'effect.imaginary']], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertContains('TERM_UNKNOWN_EFFECT', self::codes($issues));
        self::assertSame(0, $issues[0]->termIndex, 'The issue must point at the offending term.');
    }

    public function testAcceptsAnEffectSomeAbilityApplies(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'target.has_effect', 'effectId' => 'effect.bleeding']], 'ability' => 'ability.strike'],
            self::always('ability.strike'),
        ]);

        self::assertSame([], self::codes($issues));
    }

    public function testRejectsAPercentageOutsideZeroToOneHundred(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'self.health_percent', 'operator' => 'lt', 'value' => 150]], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertContains('TERM_PERCENT_OUT_OF_RANGE', self::codes($issues));
    }

    public function testRejectsANumericSubjectWithoutAnOperator(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'self.focus']], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertContains('TERM_MALFORMED', self::codes($issues));
    }

    public function testRejectsAnUnknownSubject(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'self.morale', 'operator' => 'gt', 'value' => 3]], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        self::assertContains('TERM_MALFORMED', self::codes($issues));
    }

    /**
     * A rule after an unconditional, always-available one can never fire. Worth
     * telling the player, but not worth refusing the plan over.
     */
    public function testUnreachableRulesAreWarningsRatherThanErrors(): void
    {
        $issues = self::validate([
            self::always('ability.strike'),
            ['condition' => [['subject' => 'round', 'operator' => 'gte', 'value' => 2]], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ]);

        $unreachable = array_values(array_filter(
            $issues,
            static fn (PlanIssue $i): bool => $i->code === PlanIssueCode::UnreachableRule,
        ));

        self::assertNotEmpty($unreachable);

        foreach ($issues as $issue) {
            self::assertFalse($issue->code->isBlocking(), 'Unreachable rules must not block a save.');
        }
    }

    /**
     * An unconditional rule whose ability *can* be unavailable still falls
     * through, so it blocks nothing.
     */
    public function testAnUnconditionalButUnavailableAbilityDoesNotBlockLaterRules(): void
    {
        $issues = self::validate([
            self::always('ability.costly'),
            self::always('ability.strike'),
        ]);

        self::assertSame([], self::codes($issues));
    }

    /**
     * An editor that reports one problem per save makes fixing a plan a
     * guessing game.
     */
    public function testReportsEveryProblemAtOnce(): void
    {
        $issues = self::validate([
            ['condition' => [['subject' => 'self.health_percent', 'operator' => 'lt', 'value' => 500]], 'ability' => 'ability.nonexistent'],
            ['condition' => [['subject' => 'target.has_effect', 'effectId' => 'effect.imaginary']], 'ability' => 'ability.costly'],
        ]);

        $codes = self::codes($issues);

        self::assertContains('RULE_UNKNOWN_ABILITY', $codes);
        self::assertContains('TERM_PERCENT_OUT_OF_RANGE', $codes);
        self::assertContains('TERM_UNKNOWN_EFFECT', $codes);
        self::assertContains('PLAN_FINAL_RULE_NOT_UNCONDITIONAL', $codes);
    }

    /**
     * Anything the validator accepts must be constructible, or the handler
     * would reject a plan the validator just approved.
     */
    public function testAnythingAcceptedCanBeBuilt(): void
    {
        $rules = [
            ['condition' => [['subject' => 'self.health_percent', 'operator' => 'lte', 'value' => 35]], 'ability' => 'ability.costly'],
            ['condition' => [['subject' => 'enemy.count', 'operator' => 'gte', 'value' => 2], ['subject' => 'self.focus', 'operator' => 'gte', 'value' => 12]], 'ability' => 'ability.costly'],
            self::always('ability.strike'),
        ];

        self::assertSame([], self::codes(self::validate($rules)));

        $plan = BattlePlan::fromArray($rules);

        self::assertCount(3, $plan->rules);
        self::assertSame('ability.strike', $plan->rules[2]->abilityId);
    }
}
