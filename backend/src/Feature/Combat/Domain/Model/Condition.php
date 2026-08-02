<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * A conjunction of up to three terms.
 *
 * Only AND is supported. OR is deliberately absent: the same effect is achieved
 * by writing two consecutive rules, which keeps every rule readable at a glance
 * and keeps the evaluator's cost bounded and obvious. Adding OR would also make
 * the "which rule fired and why" explanation materially harder to render, and
 * that explanation is what makes the mechanic learnable.
 */
final readonly class Condition
{
    public const int MAX_TERMS = 3;

    /** @var list<ConditionTerm> */
    public array $terms;

    /**
     * @param list<ConditionTerm> $terms
     */
    public function __construct(array $terms)
    {
        if ($terms === []) {
            throw new InvalidArgumentException('A condition requires at least one term.');
        }

        if (count($terms) > self::MAX_TERMS) {
            throw new InvalidArgumentException(
                sprintf('A condition may combine at most %d terms.', self::MAX_TERMS),
            );
        }

        foreach ($terms as $term) {
            if ($term->subject === ConditionSubject::Always && count($terms) > 1) {
                throw new InvalidArgumentException('The "always" subject cannot be combined with other terms.');
            }
        }

        $this->terms = array_values($terms);
    }

    public static function always(): self
    {
        return new self([ConditionTerm::always()]);
    }

    public function isUnconditional(): bool
    {
        return count($this->terms) === 1
            && $this->terms[0]->subject === ConditionSubject::Always;
    }

    public function requiresTarget(): bool
    {
        foreach ($this->terms as $term) {
            if ($term->subject->requiresTarget()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, string|int>>
     */
    public function toArray(): array
    {
        return array_map(static fn (ConditionTerm $t): array => $t->toArray(), $this->terms);
    }
}
