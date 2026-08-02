<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * The ordered rule list a participant fights by — the game's signature mechanic.
 *
 * Rules are evaluated top-down each turn; the first whose condition holds and
 * whose ability is currently usable is executed. See docs/game-bible.md
 * section 5 and docs/combat.md section 5.
 */
final readonly class BattlePlan
{
    public const int MAX_RULES = 8;

    /** @var list<PlanRule> */
    public array $rules;

    /**
     * @param list<PlanRule> $rules
     */
    public function __construct(array $rules)
    {
        if ($rules === []) {
            throw new InvalidArgumentException('A battle plan requires at least one rule.');
        }

        if (count($rules) > self::MAX_RULES) {
            throw new InvalidArgumentException(
                sprintf('A battle plan may contain at most %d rules.', self::MAX_RULES),
            );
        }

        $rules = array_values($rules);
        $last = $rules[count($rules) - 1];

        if (!$last->condition->isUnconditional()) {
            throw new InvalidArgumentException(
                'The final rule of a battle plan must be unconditional, so that evaluation always resolves.',
            );
        }

        foreach ($rules as $index => $rule) {
            if ($rule->condition->isUnconditional() && $index !== count($rules) - 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Rule %d is unconditional, so no rule after it could ever fire.',
                        $index + 1,
                    ),
                );
            }
        }

        $this->rules = $rules;
    }

    /**
     * A plan that does nothing but repeat one ability. Used as the default for
     * new characters, who must be able to play without opening the editor.
     */
    public static function singleAbility(string $abilityId): self
    {
        return new self([new PlanRule(Condition::always(), $abilityId)]);
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            throw new InvalidArgumentException('A battle plan requires at least one rule.');
        }

        return new self(array_map(
            static fn (array $rule): PlanRule => PlanRule::fromArray($rule),
            array_values($data),
        ));
    }

    /**
     * @return list<array{condition: list<array<string, string|int>>, abilityId: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (PlanRule $r): array => $r->toArray(), $this->rules);
    }
}
