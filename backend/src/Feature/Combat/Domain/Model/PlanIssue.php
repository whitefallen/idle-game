<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * One reason a battle plan was rejected.
 *
 * Structured rather than a message, because the editor has to point at the
 * offending rule. A plan is authored as a list, and "rule 3's ability is on
 * cooldown so it can never be your fallback" is only actionable if the UI knows
 * it means rule 3.
 *
 * The code is a stable identifier the client localises; the message is a
 * developer-facing fallback, exactly as with API error codes.
 */
final readonly class PlanIssue
{
    public function __construct(
        public PlanIssueCode $code,
        public string $message,
        /** 0-based index of the offending rule, or null when the plan as a whole is at fault. */
        public ?int $ruleIndex = null,
        /** 0-based index of the offending condition term, when the fault is narrower than a rule. */
        public ?int $termIndex = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['code' => $this->code->value, 'message' => $this->message];

        if ($this->ruleIndex !== null) {
            $data['rule'] = $this->ruleIndex;
        }

        if ($this->termIndex !== null) {
            $data['term'] = $this->termIndex;
        }

        return $data;
    }
}
