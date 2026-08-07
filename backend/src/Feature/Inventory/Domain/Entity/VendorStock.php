<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The frozen inputs behind one character's vendor stock for one UTC day.
 *
 * The offers themselves are still never stored — they stay fully derived from
 * (characterId, dateKey, referenceItemLevel, luck) through
 * VendorStockGenerator, per docs/data-model.md's rule against storing derived
 * values. What is stored is only the part of that tuple a player can change
 * during the day.
 *
 * That distinction is the whole point of this table. Seeding on the character
 * and the date already made a plain page refresh harmless, but the roll also
 * consumed the character's *current* level, Luck and average equipped item
 * level — so unequipping a weapon, allocating a point into Luck or levelling
 * up re-rolled the day's eight offers. That is a free reroll, repeatable as
 * often as a player is willing to click, and it defeated the daily cadence the
 * Vendor is priced around (docs/vendor.md section 2). Freezing those inputs on
 * first sight of the stock closes it: the offers a character sees at 09:00 are
 * the offers they can buy at 23:00, whatever they did to their gear in between.
 *
 * Written once and never updated — a row is a snapshot, not a mutable record.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_stock')]
// One snapshot per character per day, and the index every read looks the
// snapshot up by. It is also what makes the insert safe to race: concurrent
// first views resolve through ON CONFLICT DO NOTHING rather than both writing
// a different reroll of the same day.
#[ORM\UniqueConstraint(name: 'uq_vendor_stock_character_day', columns: ['character_id', 'date_key'])]
// Snapshots are pruned by age (RetentionPolicy::all), and an unindexed cutoff
// column turns that job into a full scan.
#[ORM\Index(name: 'idx_vendor_stock_created_at', columns: ['created_at'])]
class VendorStock
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    /** The UTC day this stock belongs to, as `Y-m-d`. */
    #[ORM\Column(type: 'string', length: 10)]
    private string $dateKey;

    /**
     * The character's reference item level at the moment the day's stock was
     * first seen — the value the band and every price derive from
     * (VendorRules::referenceItemLevel).
     */
    #[ORM\Column(type: 'integer')]
    private int $referenceItemLevel;

    /** The character's Luck at that same moment; it biases the rarity roll. */
    #[ORM\Column(type: 'integer')]
    private int $luck;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $characterId,
        string $dateKey,
        int $referenceItemLevel,
        int $luck,
        DateTimeImmutable $now,
    ) {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->dateKey = $dateKey;
        $this->referenceItemLevel = $referenceItemLevel;
        $this->luck = $luck;
        $this->createdAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function dateKey(): string
    {
        return $this->dateKey;
    }

    public function referenceItemLevel(): int
    {
        return $this->referenceItemLevel;
    }

    public function luck(): int
    {
        return $this->luck;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
