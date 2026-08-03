<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Model;

use InvalidArgumentException;

/**
 * An immutable attribute allocation.
 *
 * Only the *allocated* values live here. Equipment contributions are added
 * separately when derived stats are computed, so unequipping can never leave a
 * character in an invalid state. See docs/data-model.md section 2.
 */
final readonly class Attributes
{
    public const int BASE_VALUE = 5;

    private function __construct(
        public int $strength,
        public int $dexterity,
        public int $intelligence,
        public int $constitution,
        public int $luck,
    ) {
        foreach (self::asArrayOf($this) as $name => $value) {
            if ($value < self::BASE_VALUE) {
                throw new InvalidArgumentException(sprintf(
                    'Attribute %s cannot fall below the base value of %d, got %d.',
                    $name,
                    self::BASE_VALUE,
                    $value,
                ));
            }
        }
    }

    /** The allocation every character begins with. */
    public static function starting(): self
    {
        return new self(
            self::BASE_VALUE,
            self::BASE_VALUE,
            self::BASE_VALUE,
            self::BASE_VALUE,
            self::BASE_VALUE,
        );
    }

    public static function of(int $str, int $dex, int $int, int $con, int $luk): self
    {
        return new self($str, $dex, $int, $con, $luk);
    }

    public function get(Attribute $attribute): int
    {
        return match ($attribute) {
            Attribute::Strength => $this->strength,
            Attribute::Dexterity => $this->dexterity,
            Attribute::Intelligence => $this->intelligence,
            Attribute::Constitution => $this->constitution,
            Attribute::Luck => $this->luck,
        };
    }

    /**
     * Returns a new allocation with the given points added.
     *
     * @param array<string, int> $additions Keyed by Attribute value.
     */
    public function plus(array $additions): self
    {
        $values = self::asArrayOf($this);

        foreach ($additions as $key => $amount) {
            $attribute = Attribute::tryFrom((string) $key)
                ?? throw new InvalidArgumentException(sprintf('Unknown attribute "%s".', (string) $key));

            if ($amount < 0) {
                throw new InvalidArgumentException('Attribute points cannot be removed by allocation.');
            }

            $values[$attribute->value] += $amount;
        }

        return new self(
            $values[Attribute::Strength->value],
            $values[Attribute::Dexterity->value],
            $values[Attribute::Intelligence->value],
            $values[Attribute::Constitution->value],
            $values[Attribute::Luck->value],
        );
    }

    /**
     * Points spent above the base allocation. Used to verify that a character's
     * allocation matches the points its level actually granted.
     */
    public function pointsSpent(): int
    {
        return array_sum(self::asArrayOf($this)) - (self::BASE_VALUE * count(Attribute::cases()));
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return self::asArrayOf($this);
    }

    /**
     * @return array<string, int>
     */
    private static function asArrayOf(self $attributes): array
    {
        return [
            Attribute::Strength->value => $attributes->strength,
            Attribute::Dexterity->value => $attributes->dexterity,
            Attribute::Intelligence->value => $attributes->intelligence,
            Attribute::Constitution->value => $attributes->constitution,
            Attribute::Luck->value => $attributes->luck,
        ];
    }
}
