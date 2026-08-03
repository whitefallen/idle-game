<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Model;

/**
 * Rarity affects affix count, never base stats.
 *
 * A Common and a Legendary of the same base at the same item level have
 * identical base damage. That keeps the base-item curve clean and means an
 * early Legendary is exciting without being a tier skip.
 *
 * See docs/items.md section 4.
 */
enum ItemRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Epic = 'epic';
    case Legendary = 'legendary';

    /**
     * Relative drop weight. Rarity is rolled independently of the item pool, so
     * this distribution is tuned in one place rather than duplicated across
     * every drop table.
     */
    public function dropWeight(): int
    {
        return match ($this) {
            self::Common => 5500,
            self::Uncommon => 3000,
            self::Rare => 1200,
            self::Epic => 280,
            self::Legendary => 20,
        };
    }

    public function minimumAffixes(): int
    {
        return match ($this) {
            self::Common => 0,
            self::Uncommon => 1,
            self::Rare => 3,
            self::Epic, self::Legendary => 4,
        };
    }

    public function maximumAffixes(): int
    {
        return match ($this) {
            self::Common => 0,
            self::Uncommon => 2,
            self::Rare => 3,
            self::Epic, self::Legendary => 4,
        };
    }

    /**
     * Ordered weakest to strongest, so a Luck floor can be expressed as a
     * minimum index rather than a special case per rarity.
     *
     * @return list<self>
     */
    public static function ascending(): array
    {
        return [self::Common, self::Uncommon, self::Rare, self::Epic, self::Legendary];
    }
}
