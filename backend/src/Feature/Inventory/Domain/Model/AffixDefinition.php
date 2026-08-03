<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

use InvalidArgumentException;

/**
 * An affix as authored in content, with its tiers.
 *
 * Higher tiers unlock at higher item levels and roll larger values, so a
 * low-level drop cannot carry a late-game modifier.
 */
final readonly class AffixDefinition
{
    /**
     * @param list<AffixTier> $tiers Ordered by minimum item level, ascending.
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public string $pool,
        public AffixKind $kind,
        public ModifierStat $stat,
        public ModifierMode $mode,
        public array $tiers,
    ) {
        if ($tiers === []) {
            throw new InvalidArgumentException(sprintf('Affix "%s" declares no tiers.', $id));
        }

        if ($mode === ModifierMode::Percent && !$stat->supportsPercent()) {
            throw new InvalidArgumentException(sprintf(
                'Affix "%s" applies a percentage to "%s", which has no base value to scale.',
                $id,
                $stat->value,
            ));
        }
    }

    /**
     * The strongest tier available at this item level, or null when the affix
     * cannot appear on an item this low.
     */
    public function tierFor(int $itemLevel): ?AffixTier
    {
        $best = null;

        foreach ($this->tiers as $tier) {
            if ($tier->minimumItemLevel <= $itemLevel
                && ($best === null || $tier->minimumItemLevel > $best->minimumItemLevel)
            ) {
                $best = $tier;
            }
        }

        return $best;
    }

    public function isAvailableAt(int $itemLevel): bool
    {
        return $this->tierFor($itemLevel) !== null;
    }
}
