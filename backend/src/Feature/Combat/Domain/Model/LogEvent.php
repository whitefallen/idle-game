<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * One entry in a combat log.
 *
 * Events carry entity ids and numbers only, never localised strings: display
 * text is resolved client-side from localisation keys, so the server never
 * needs to know the player's language and a stored log stays renderable in any
 * language added later. See docs/combat.md section 7.
 */
final readonly class LogEvent
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public string $type,
        public array $data,
    ) {
    }

    public static function roundStart(int $round): self
    {
        return new self('round.start', ['round' => $round]);
    }

    /**
     * Records which battle plan rule fired. This is what turns a loss into a
     * lesson rather than a shrug, and it is a product requirement rather than a
     * debugging aid. See docs/combat.md section 5.3.
     */
    public static function planMatched(string $actorId, int $ruleIndex, string $abilityId): self
    {
        return new self('plan.matched', [
            'actor' => $actorId,
            'rule' => $ruleIndex,
            'ability' => $abilityId,
        ]);
    }

    /**
     * No rule resolved to a usable ability. Recorded rather than silently
     * skipped, because a plan that cannot act is precisely what the player
     * needs to see.
     */
    public static function planExhausted(string $actorId): self
    {
        return new self('plan.exhausted', ['actor' => $actorId]);
    }

    public static function damage(
        string $sourceId,
        string $targetId,
        int $amount,
        bool $critical,
        DamageSchool $school,
        int $targetHealthAfter,
    ): self {
        return new self('damage', [
            'source' => $sourceId,
            'target' => $targetId,
            'amount' => $amount,
            'crit' => $critical,
            'school' => $school->value,
            'targetHealth' => $targetHealthAfter,
        ]);
    }

    public static function miss(string $sourceId, string $targetId): self
    {
        return new self('miss', ['source' => $sourceId, 'target' => $targetId]);
    }

    public static function heal(string $sourceId, string $targetId, int $amount, int $targetHealthAfter): self
    {
        return new self('heal', [
            'source' => $sourceId,
            'target' => $targetId,
            'amount' => $amount,
            'targetHealth' => $targetHealthAfter,
        ]);
    }

    public static function effectApplied(string $sourceId, string $targetId, string $effectId, int $rounds): self
    {
        return new self('effect.applied', [
            'source' => $sourceId,
            'target' => $targetId,
            'effect' => $effectId,
            'rounds' => $rounds,
        ]);
    }

    public static function effectExpired(string $targetId, string $effectId): self
    {
        return new self('effect.expired', ['target' => $targetId, 'effect' => $effectId]);
    }

    public static function effectTicked(
        string $targetId,
        string $effectId,
        int $amount,
        int $targetHealthAfter,
    ): self {
        return new self('effect.ticked', [
            'target' => $targetId,
            'effect' => $effectId,
            'amount' => $amount,
            'targetHealth' => $targetHealthAfter,
        ]);
    }

    public static function died(string $participantId): self
    {
        return new self('died', ['participant' => $participantId]);
    }

    public static function encounterEnd(Outcome $outcome, int $rounds): self
    {
        return new self('encounter.end', ['outcome' => $outcome->value, 'rounds' => $rounds]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['t' => $this->type, ...$this->data];
    }
}
