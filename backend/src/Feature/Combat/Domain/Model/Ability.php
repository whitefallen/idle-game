<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * A usable action. Defined in content, never in code.
 *
 * An ability may deal damage, heal, apply an effect, or any combination; a
 * coefficient of zero simply switches that facet off. This keeps the engine
 * free of per-ability branching, which is what allows a designer to add an
 * ability by writing YAML.
 */
final readonly class Ability
{
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $focusCost,
        public int $cooldownRounds,
        /** Multiplier on the attacker's damage, in basis points. 0 deals none. */
        public int $damageCoefficientBp,
        /** Multiplier on the attacker's damage, applied as healing. 0 heals none. */
        public int $healCoefficientBp,
        public DamageSchool $school,
        public TargetSelector $selector,
        public ?string $effectId = null,
        public int $effectChanceBp = 0,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('An ability requires an id.');
        }

        if ($focusCost < 0) {
            throw new InvalidArgumentException(sprintf('Ability "%s" cannot have a negative Focus cost.', $id));
        }

        if ($cooldownRounds < 0) {
            throw new InvalidArgumentException(sprintf('Ability "%s" cannot have a negative cooldown.', $id));
        }

        if ($damageCoefficientBp < 0 || $healCoefficientBp < 0) {
            throw new InvalidArgumentException(sprintf('Ability "%s" cannot have negative coefficients.', $id));
        }

        if ($damageCoefficientBp === 0 && $healCoefficientBp === 0 && $effectId === null) {
            throw new InvalidArgumentException(
                sprintf('Ability "%s" would do nothing: it deals no damage, heals nothing and applies no effect.', $id),
            );
        }

        if ($effectId !== null && ($effectChanceBp < 1 || $effectChanceBp > 10000)) {
            throw new InvalidArgumentException(
                sprintf('Ability "%s" applies an effect, so its chance must be within [1, 10000].', $id),
            );
        }

        if ($effectId === null && $effectChanceBp !== 0) {
            throw new InvalidArgumentException(
                sprintf('Ability "%s" declares an effect chance but no effect.', $id),
            );
        }
    }

    /**
     * Whether this ability is free and always available, and therefore usable
     * as a battle plan's guaranteed fallback.
     */
    public function isAlwaysAvailable(): bool
    {
        return $this->focusCost === 0 && $this->cooldownRounds === 0;
    }
}
