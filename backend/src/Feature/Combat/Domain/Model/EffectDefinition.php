<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * A timed effect that can be attached to a participant.
 *
 * Stacking rule: reapplying an effect refreshes its remaining duration and does
 * not accumulate magnitude. Magnitude stacking makes encounters swing on
 * application order and is the usual source of unbalanceable damage-over-time
 * builds; refresh semantics keep the ceiling predictable.
 */
final readonly class EffectDefinition
{
    public function __construct(
        public string $id,
        public string $localisationKey,
        public EffectKind $kind,
        /**
         * For DamageOverTime and HealOverTime, a flat amount per round.
         * For DamageModifier, a signed basis-point adjustment.
         */
        public int $magnitude,
        public int $durationRounds,
        public DamageSchool $school = DamageSchool::Physical,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('An effect requires an id.');
        }

        if ($durationRounds < 1) {
            throw new InvalidArgumentException(
                sprintf('Effect "%s" must last at least one round.', $id),
            );
        }

        if ($kind !== EffectKind::DamageModifier && $magnitude < 0) {
            throw new InvalidArgumentException(
                sprintf('Effect "%s" must not have a negative magnitude.', $id),
            );
        }
    }
}
