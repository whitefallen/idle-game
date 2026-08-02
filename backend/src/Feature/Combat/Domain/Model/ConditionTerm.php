<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * A single comparison within a battle plan condition.
 *
 * The constructor rejects malformed combinations, so an instance is always
 * evaluable. Battle plans arrive from untrusted client input; making the value
 * object impossible to construct in an invalid state means the evaluator never
 * has to defend against one.
 */
final readonly class ConditionTerm
{
    public function __construct(
        public ConditionSubject $subject,
        public ?ComparisonOperator $operator = null,
        public ?int $value = null,
        public ?string $effectId = null,
    ) {
        if ($subject->isNumeric()) {
            if ($operator === null || $value === null) {
                throw new InvalidArgumentException(
                    sprintf('Subject "%s" requires an operator and a value.', $subject->value),
                );
            }

            if ($effectId !== null) {
                throw new InvalidArgumentException(
                    sprintf('Subject "%s" does not take an effect id.', $subject->value),
                );
            }
        }

        if ($subject->requiresEffectId()) {
            if ($effectId === null || $effectId === '') {
                throw new InvalidArgumentException(
                    sprintf('Subject "%s" requires an effect id.', $subject->value),
                );
            }

            if ($operator !== null || $value !== null) {
                throw new InvalidArgumentException(
                    sprintf('Subject "%s" does not take an operator or value.', $subject->value),
                );
            }
        }

        if ($subject === ConditionSubject::Always && ($operator !== null || $value !== null || $effectId !== null)) {
            throw new InvalidArgumentException('The "always" subject takes no arguments.');
        }

        if ($value !== null && ($value < 0 || $value > 100000)) {
            throw new InvalidArgumentException('Condition values must be within [0, 100000].');
        }
    }

    public static function always(): self
    {
        return new self(ConditionSubject::Always);
    }

    public static function numeric(ConditionSubject $subject, ComparisonOperator $operator, int $value): self
    {
        return new self($subject, $operator, $value);
    }

    public static function hasEffect(ConditionSubject $subject, string $effectId): self
    {
        return new self($subject, effectId: $effectId);
    }

    /**
     * The inverse of {@see toArray()}.
     *
     * Battle plans arrive from three directions — content files, the database
     * and untrusted client input — and all three use this one parser, so there
     * is a single place where a malformed plan is rejected.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $subject = ConditionSubject::tryFrom((string) ($data['subject'] ?? ''))
            ?? throw new InvalidArgumentException(
                sprintf('Unknown condition subject "%s".', (string) ($data['subject'] ?? '')),
            );

        $operator = isset($data['operator'])
            ? (ComparisonOperator::tryFrom((string) $data['operator'])
                ?? throw new InvalidArgumentException(
                    sprintf('Unknown comparison operator "%s".', (string) $data['operator']),
                ))
            : null;

        return new self(
            subject: $subject,
            operator: $operator,
            value: isset($data['value']) ? (int) $data['value'] : null,
            effectId: isset($data['effectId']) ? (string) $data['effectId'] : null,
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        $data = ['subject' => $this->subject->value];

        if ($this->operator !== null) {
            $data['operator'] = $this->operator->value;
        }

        if ($this->value !== null) {
            $data['value'] = $this->value;
        }

        if ($this->effectId !== null) {
            $data['effectId'] = $this->effectId;
        }

        return $data;
    }
}
