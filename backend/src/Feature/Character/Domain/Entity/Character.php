<?php

declare(strict_types=1);

namespace App\Feature\Character\Domain\Entity;

use App\Feature\Character\Domain\Model\Attributes;
use App\Feature\Character\Domain\Service\DerivedStatsCalculator;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Character\Domain\Service\VigorRules;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Inventory\Domain\Model\EquipmentBonuses;
use DateTimeImmutable;
use DomainException;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * A player character.
 *
 * Attribute columns hold the *allocated* values only; equipment contributions
 * are added when derived stats are computed, so unequipping can never leave the
 * character in an invalid state.
 *
 * No derived value is stored, with the single documented exception of
 * powerScore, which is advisory and exists only so leaderboards and matchmaking
 * can be one indexed query. See ADR-0006.
 */
#[ORM\Entity]
// Not `character`: CHARACTER is a reserved SQL keyword, and a table name that
// must be quoted in every hand-written query is a permanent papercut.
#[ORM\Table(name: 'game_character')]
#[ORM\UniqueConstraint(name: 'uq_character_name', columns: ['name'])]
#[ORM\Index(name: 'idx_character_account_id', columns: ['account_id'])]
#[ORM\Index(name: 'idx_character_power_score', columns: ['power_score'])]
#[ORM\Index(name: 'idx_character_level', columns: ['level'])]
class Character
{
    public const int NAME_MIN_LENGTH = 3;

    public const int NAME_MAX_LENGTH = 24;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;

    #[ORM\Column(type: 'string', length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $level = 1;

    #[ORM\Column(type: 'bigint')]
    private int $experience = 0;

    #[ORM\Column(type: 'bigint')]
    private int $gold = 0;

    #[ORM\Column(type: 'bigint')]
    private int $emberdust = 0;

    #[ORM\Column(type: 'integer')]
    private int $unspentPoints = ProgressionRules::STARTING_POINTS;

    #[ORM\Column(type: 'integer')]
    private int $strength = Attributes::BASE_VALUE;

    #[ORM\Column(type: 'integer')]
    private int $dexterity = Attributes::BASE_VALUE;

    #[ORM\Column(type: 'integer')]
    private int $intelligence = Attributes::BASE_VALUE;

    #[ORM\Column(type: 'integer')]
    private int $constitution = Attributes::BASE_VALUE;

    #[ORM\Column(type: 'integer')]
    private int $luck = Attributes::BASE_VALUE;

    #[ORM\Column(type: 'integer')]
    private int $vigorCurrent = VigorRules::CAP;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $vigorTickedAt;

    /**
     * When the last Vigor-spending activity resolved, or null if none ever has.
     *
     * Nullable rather than defaulted to the creation time so that "has never
     * spent Vigor" is a distinct, readable state instead of being inferred from
     * a timestamp that happens to be old. A brand new character is not gated.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $vigorSpentAt = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $battlePlan;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $abilityIds;

    #[ORM\Column(type: 'integer')]
    private int $powerScore = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $abilityIds
     */
    public function __construct(
        Uuid $id,
        Uuid $accountId,
        string $name,
        BattlePlan $battlePlan,
        array $abilityIds,
        DateTimeImmutable $now,
    ) {
        self::assertValidName($name);

        if ($abilityIds === []) {
            throw new InvalidArgumentException('A character must know at least one ability.');
        }

        $this->id = $id;
        $this->accountId = $accountId;
        $this->name = $name;
        $this->battlePlan = $battlePlan->toArray();
        $this->abilityIds = $abilityIds;
        $this->vigorTickedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        // A new character wears nothing, so the starting score is allocation only.
        $this->powerScore = DerivedStatsCalculator::powerScore($this->level, $this->attributes(), EquipmentBonuses::none());
    }

    public static function assertValidName(string $name): void
    {
        $length = mb_strlen($name);

        if ($length < self::NAME_MIN_LENGTH || $length > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A character name must be between %d and %d characters.',
                self::NAME_MIN_LENGTH,
                self::NAME_MAX_LENGTH,
            ));
        }

        if (preg_match('/^[\p{L}][\p{L}\p{N} \'-]*$/u', $name) !== 1) {
            throw new InvalidArgumentException(
                'A character name must start with a letter and may contain only letters, '
                . 'numbers, spaces, apostrophes and hyphens.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Identity and read model
    // -----------------------------------------------------------------

    public function id(): Uuid
    {
        return $this->id;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function isOwnedBy(Uuid $accountId): bool
    {
        return $this->accountId->equals($accountId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function experience(): int
    {
        return $this->experience;
    }

    public function experienceToNextLevel(): int
    {
        return ProgressionRules::experienceToNextLevel($this->level);
    }

    public function gold(): int
    {
        return $this->gold;
    }

    public function emberdust(): int
    {
        return $this->emberdust;
    }

    public function unspentPoints(): int
    {
        return $this->unspentPoints;
    }

    public function powerScore(): int
    {
        return $this->powerScore;
    }

    public function attributes(): Attributes
    {
        return Attributes::of(
            $this->strength,
            $this->dexterity,
            $this->intelligence,
            $this->constitution,
            $this->luck,
        );
    }

    /**
     * Derived stats are deliberately not available here.
     *
     * They depend on equipped items, which belong to a different aggregate that
     * this entity cannot and should not load. Use
     * {@see \App\Feature\Character\Application\CharacterStats} instead, which
     * combines allocation with equipment.
     */

    public function battlePlan(): BattlePlan
    {
        return BattlePlan::fromArray($this->battlePlan);
    }

    /**
     * @return list<string>
     */
    public function abilityIds(): array
    {
        return $this->abilityIds;
    }

    // -----------------------------------------------------------------
    // Vigor
    // -----------------------------------------------------------------

    /**
     * Brings Vigor up to date from elapsed time. Idempotent: calling it twice
     * with the same clock value regenerates nothing the second time.
     */
    public function regenerateVigor(DateTimeImmutable $now): void
    {
        $result = VigorRules::regenerate(
            $this->vigorCurrent,
            $this->vigorTickedAt->getTimestamp(),
            $now->getTimestamp(),
        );

        $this->vigorCurrent = $result['current'];
        $this->vigorTickedAt = $now->setTimestamp($result['tickedAt']);
    }

    public function vigor(): int
    {
        return $this->vigorCurrent;
    }

    public function vigorTickedAt(): DateTimeImmutable
    {
        return $this->vigorTickedAt;
    }

    public function vigorFullAt(): DateTimeImmutable
    {
        return $this->vigorTickedAt->setTimestamp(
            VigorRules::fullAt($this->vigorCurrent, $this->vigorTickedAt->getTimestamp()),
        );
    }

    public function hasVigor(int $cost): bool
    {
        return $this->vigorCurrent >= $cost;
    }

    public function vigorSpentAt(): ?DateTimeImmutable
    {
        return $this->vigorSpentAt;
    }

    /**
     * Whether a new Vigor-spending activity may begin.
     *
     * A character runs one at a time: the previous activity must have resolved
     * and the gate interval must have elapsed. See {@see VigorRules::ACTIVITY_GATE_SECONDS}.
     */
    public function canStartVigorActivity(DateTimeImmutable $now): bool
    {
        return VigorRules::canStartActivity($this->vigorSpentAt?->getTimestamp(), $now->getTimestamp());
    }

    /** Zero when an activity may begin now. For the client's countdown. */
    public function secondsUntilVigorActivity(DateTimeImmutable $now): int
    {
        return VigorRules::secondsUntilReady($this->vigorSpentAt?->getTimestamp(), $now->getTimestamp());
    }

    public function vigorActivityReadyAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimestamp(
            max($now->getTimestamp(), VigorRules::activityReadyAt($this->vigorSpentAt?->getTimestamp())),
        );
    }

    /**
     * Spends Vigor and starts the activity gate.
     *
     * The gate is enforced here as well as in the application layer. The caller
     * checks it in order to return a useful error, but this is the invariant:
     * an entity that can be driven into an illegal state by a caller that
     * forgot a precondition is not an aggregate, it is a data bag. The lock the
     * caller holds makes the check-then-spend sequence atomic; this makes it
     * unskippable.
     */
    public function spendVigor(int $cost, DateTimeImmutable $now): void
    {
        if ($cost < 0) {
            throw new InvalidArgumentException('Vigor cost cannot be negative.');
        }

        if (!$this->canStartVigorActivity($now)) {
            throw new DomainException('A Vigor-spending activity is already in progress.');
        }

        if ($this->vigorCurrent < $cost) {
            throw new DomainException('Insufficient Vigor.');
        }

        $this->vigorSpentAt = $now;

        // Leaving the pool below the cap starts the regeneration clock from
        // this moment, so time spent full is not retroactively credited.
        if ($this->vigorCurrent >= VigorRules::CAP && $cost > 0) {
            $this->vigorTickedAt = $now;
        }

        $this->vigorCurrent -= $cost;
        $this->updatedAt = $now;
    }

    /**
     * Returns Vigor without lifting the activity gate.
     *
     * Deliberate. A refund makes the player whole for a *cost*; the gate is not
     * a cost, it is the record that an activity ran. The fight happened, it
     * consumed the server work, and the next one should still be paced from it.
     * Clearing the gate here would also make a draw the cheapest way to fight
     * twice in a row, which is a strange thing to reward.
     */
    public function refundVigor(int $amount, DateTimeImmutable $now): void
    {
        $this->vigorCurrent = min(VigorRules::CAP, $this->vigorCurrent + max(0, $amount));
        $this->updatedAt = $now;
    }

    // -----------------------------------------------------------------
    // Progression
    // -----------------------------------------------------------------

    /**
     * @return int The number of levels gained, so the caller can emit one
     *             PlayerLeveledUp event per level.
     */
    public function awardExperience(int $amount, EquipmentBonuses $equipment, DateTimeImmutable $now): int
    {
        $result = ProgressionRules::applyExperience($this->level, $this->experience, $amount);

        $this->level = $result['level'];
        $this->experience = $result['experience'];
        $this->unspentPoints += $result['levelsGained'] * ProgressionRules::POINTS_PER_LEVEL;
        $this->updatedAt = $now;

        if ($result['levelsGained'] > 0) {
            $this->updatePowerScore($equipment);
        }

        return $result['levelsGained'];
    }

    public function awardGold(int $amount, DateTimeImmutable $now): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Use spendGold to reduce a balance.');
        }

        $this->gold += $amount;
        $this->updatedAt = $now;
    }

    public function spendGold(int $amount, DateTimeImmutable $now): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Gold spend cannot be negative.');
        }

        if ($this->gold < $amount) {
            throw new DomainException('Insufficient gold.');
        }

        $this->gold -= $amount;
        $this->updatedAt = $now;
    }

    /**
     * @param array<string, int> $allocation Keyed by Attribute value.
     */
    public function allocatePoints(array $allocation, EquipmentBonuses $equipment, DateTimeImmutable $now): void
    {
        $total = array_sum($allocation);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocate at least one point.');
        }

        if ($total > $this->unspentPoints) {
            throw new DomainException(sprintf(
                'Only %d unspent point(s) available, %d requested.',
                $this->unspentPoints,
                $total,
            ));
        }

        $updated = $this->attributes()->plus($allocation);

        $this->strength = $updated->strength;
        $this->dexterity = $updated->dexterity;
        $this->intelligence = $updated->intelligence;
        $this->constitution = $updated->constitution;
        $this->luck = $updated->luck;
        $this->unspentPoints -= $total;
        $this->updatedAt = $now;

        $this->updatePowerScore($equipment);
    }

    public function respec(EquipmentBonuses $equipment, DateTimeImmutable $now): void
    {
        $this->strength = Attributes::BASE_VALUE;
        $this->dexterity = Attributes::BASE_VALUE;
        $this->intelligence = Attributes::BASE_VALUE;
        $this->constitution = Attributes::BASE_VALUE;
        $this->luck = Attributes::BASE_VALUE;
        $this->unspentPoints = ProgressionRules::totalAttributePointsAt($this->level);
        $this->updatedAt = $now;

        $this->updatePowerScore($equipment);
    }

    /**
     * How many disciplines this character may have slotted at once.
     *
     * Owning a discipline is permanent; slotting is what is limited. That split
     * is the whole point of the axis — see docs/progression.md section 4.1.
     */
    public function loadoutSlots(): int
    {
        return ProgressionRules::loadoutSlotsAt($this->level);
    }

    /**
     * Replaces the slotted loadout.
     *
     * Enforces only what the character itself can know: the slot budget, that a
     * loadout is a set rather than a list, and that the battle plan it already
     * has stays executable. Whether each ability is one this character has
     * *unlocked* depends on the discipline catalogue, which is content and lives
     * outside the aggregate — the application layer checks that and produces the
     * player-facing error.
     *
     * Unslotting an ability the current plan uses is rejected rather than
     * silently repairing the plan. A plan is authored, sometimes carefully, and
     * quietly deleting a rule from it is a worse outcome than being told which
     * rule is in the way.
     *
     * @param list<string> $abilityIds
     */
    public function changeLoadout(array $abilityIds, DateTimeImmutable $now): void
    {
        $abilityIds = array_values($abilityIds);

        if ($abilityIds === []) {
            throw new DomainException('A loadout must contain at least one ability.');
        }

        if (count($abilityIds) !== count(array_unique($abilityIds))) {
            throw new DomainException('A loadout cannot slot the same ability twice.');
        }

        $slots = $this->loadoutSlots();

        if (count($abilityIds) > $slots) {
            throw new DomainException(sprintf(
                'This character has %d loadout slot(s) and %d were used.',
                $slots,
                count($abilityIds),
            ));
        }

        foreach ($this->battlePlan as $index => $rule) {
            /** @var string $abilityId */
            $abilityId = $rule['abilityId'] ?? '';

            if (!in_array($abilityId, $abilityIds, true)) {
                throw new DomainException(sprintf(
                    'Rule %d of the battle plan uses ability "%s", which this loadout does not slot.',
                    $index + 1,
                    $abilityId,
                ));
            }
        }

        $this->abilityIds = $abilityIds;
        $this->updatedAt = $now;
    }

    public function replaceBattlePlan(BattlePlan $plan, DateTimeImmutable $now): void
    {
        foreach ($plan->rules as $index => $rule) {
            if (!in_array($rule->abilityId, $this->abilityIds, true)) {
                throw new DomainException(sprintf(
                    'Rule %d uses ability "%s", which this character does not know.',
                    $index + 1,
                    $rule->abilityId,
                ));
            }
        }

        $this->battlePlan = $plan->toArray();
        $this->updatedAt = $now;
    }

    /**
     * Recomputed on every event that can change it, per ADR-0006.
     *
     * Equipment is passed in rather than looked up, because it lives in another
     * aggregate. Making it a required argument of every mutator that affects
     * power is what stops a caller silently leaving the score stale — the
     * failure mode ADR-0006 names as this column's main risk.
     */
    public function updatePowerScore(EquipmentBonuses $equipment): void
    {
        $this->powerScore = DerivedStatsCalculator::powerScore($this->level, $this->attributes(), $equipment);
    }
}
