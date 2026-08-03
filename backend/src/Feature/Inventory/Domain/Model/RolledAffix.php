<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use InvalidArgumentException;

/**
 * An affix as it landed on one specific item.
 *
 * The rolled value is stored on the instance rather than re-derived, because
 * the drop RNG is not part of the combat ruleset: rebalancing a drop table must
 * not silently change the stats of items players already own.
 */
final readonly class RolledAffix
{
    public function __construct(
        public string $affixId,
        public int $tier,
        public int $value,
    ) {
        if ($affixId === '') {
            throw new InvalidArgumentException('A rolled affix requires an affix id.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['tier'] ?? 1),
            (int) ($data['value'] ?? 0),
        );
    }

    /**
     * @return array{id: string, tier: int, value: int}
     */
    public function toArray(): array
    {
        return ['id' => $this->affixId, 'tier' => $this->tier, 'value' => $this->value];
    }
}
