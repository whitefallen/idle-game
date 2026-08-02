<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Engine;

use App\Feature\Combat\Domain\Model\EffectDefinition;
use App\Feature\Combat\Domain\Model\EffectKind;
use App\Feature\Combat\Domain\Model\Participant;

/**
 * The mutable runtime state of one combatant during resolution.
 *
 * Deliberately separate from {@see Participant}, which is the immutable input
 * snapshot. Keeping them apart means the snapshot persisted with the encounter
 * can never be contaminated by in-fight mutation, and it makes it obvious at a
 * glance which values are inputs and which are working state.
 *
 * Internal to the engine; never leaves it.
 */
final class CombatantState
{
    public int $health;

    public int $focus;

    /**
     * Ability id to the first round in which it may be used again.
     *
     * Absolute rounds rather than a countdown that is decremented each round:
     * with a countdown, the decrement and the availability check both happen
     * within the same round, so a cooldown blocked for one round fewer than
     * declared and `cooldownRounds: 1` was silently a no-op.
     *
     * @var array<string, int>
     */
    private array $availableFromRound = [];

    /** @var array<string, array{effect: EffectDefinition, remaining: int, sourceId: string}> */
    private array $effects = [];

    public function __construct(
        public readonly Participant $participant,
        public readonly int $ordinal,
    ) {
        $this->health = $participant->maxHealth;
        $this->focus = $participant->maxFocus;
    }

    public function isAlive(): bool
    {
        return $this->health > 0;
    }

    public function healthPercent(): int
    {
        return intdiv($this->health * 100, $this->participant->maxHealth);
    }

    public function applyDamage(int $amount): void
    {
        $this->health = max(0, $this->health - $amount);
    }

    /** @return int The amount actually restored, after the maximum-health cap. */
    public function applyHealing(int $amount): int
    {
        $before = $this->health;
        $this->health = min($this->participant->maxHealth, $this->health + $amount);

        return $this->health - $before;
    }

    public function regenerateFocus(): void
    {
        $this->focus = min($this->participant->maxFocus, $this->focus + $this->participant->focusPerTurn);
    }

    public function canAfford(int $cost): bool
    {
        return $this->focus >= $cost;
    }

    public function spendFocus(int $cost): void
    {
        $this->focus = max(0, $this->focus - $cost);
    }

    public function isOnCooldown(string $abilityId, int $currentRound): bool
    {
        return ($this->availableFromRound[$abilityId] ?? 0) > $currentRound;
    }

    /**
     * Puts an ability on cooldown for exactly $rounds rounds after $currentRound.
     *
     * A cooldown of 1 blocks the single following round; a cooldown of 3 blocks
     * the next three.
     */
    public function startCooldown(string $abilityId, int $rounds, int $currentRound): void
    {
        if ($rounds > 0) {
            $this->availableFromRound[$abilityId] = $currentRound + $rounds + 1;
        }
    }

    /**
     * Applies an effect, refreshing the duration if it is already present.
     * Magnitude never accumulates; see {@see EffectDefinition}.
     */
    public function applyEffect(EffectDefinition $effect, string $sourceId): void
    {
        $this->effects[$effect->id] = [
            'effect' => $effect,
            'remaining' => $effect->durationRounds,
            'sourceId' => $sourceId,
        ];
    }

    public function hasEffect(string $effectId): bool
    {
        return isset($this->effects[$effectId]);
    }

    /**
     * Active effects in a stable order.
     *
     * Sorted by effect id rather than by application order: determinism rule R3
     * forbids relying on insertion order, and two effects applied in the same
     * round must tick in an order that does not depend on how the array was built.
     *
     * @return list<array{effect: EffectDefinition, remaining: int, sourceId: string}>
     */
    public function activeEffects(): array
    {
        $ids = array_keys($this->effects);
        sort($ids, SORT_STRING);

        return array_map(fn (string $id): array => $this->effects[$id], $ids);
    }

    /**
     * The combined outgoing-damage adjustment from all active modifiers, in
     * basis points. Modifiers stack additively with each other, which keeps
     * stacking legible and avoids the multiplicative explosion that makes
     * late-game builds unbalanceable. See docs/combat.md section 6.
     */
    public function damageModifierBp(): int
    {
        $total = 0;

        foreach ($this->activeEffects() as $entry) {
            if ($entry['effect']->kind === EffectKind::DamageModifier) {
                $total += $entry['effect']->magnitude;
            }
        }

        return $total;
    }

    /**
     * Decrements every effect's remaining duration.
     *
     * @return list<string> The ids of effects that expired.
     */
    public function tickEffectDurations(): array
    {
        $expired = [];

        foreach ($this->activeEffects() as $entry) {
            $id = $entry['effect']->id;
            $remaining = $this->effects[$id]['remaining'] - 1;

            if ($remaining <= 0) {
                unset($this->effects[$id]);
                $expired[] = $id;

                continue;
            }

            $this->effects[$id]['remaining'] = $remaining;
        }

        return $expired;
    }
}
