<?php

declare(strict_types=1);

namespace App\Feature\Holding\Domain\Entity;

use App\Feature\Holding\Domain\Model\HoldingYield;
use App\Feature\Holding\Domain\Model\ProductionSlot;
use App\Feature\Holding\Domain\Service\HoldingRules;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * A character's fortified waystation on the beacon-line: the idle layer.
 *
 * The aggregate owns the accrual anchors and nothing else. It does not know
 * what a material is worth, which lines a character has unlocked, or where the
 * gold ends up — rates and level come in as arguments, and the granting happens
 * in the application layer. That is what keeps the whole idle calculation
 * testable without a database, a clock or a content library.
 *
 * The cap is **derived from level rather than stored**, a deliberate departure
 * from the `cap_seconds` column in docs/data-model.md. A stored cap is a
 * derived value in the sense CLAUDE.md forbids: it would have to be rewritten
 * on every level-up, and any handler that forgot would leave a character
 * permanently capped at an old value with nothing to detect it. Level is
 * already loaded whenever a claim happens, so deriving costs nothing.
 *
 * Claims lock this row with SELECT … FOR UPDATE (docs/idle.md rule T2). Without
 * that lock two concurrent claims read the same anchors and both pay out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'holding')]
// One Holding per character, enforced by the index rather than by a check in
// the provisioning path: two concurrent first-time reads would otherwise each
// create one, and the character would own two waystations producing in parallel.
#[ORM\UniqueConstraint(name: 'uq_holding_character', columns: ['character_id'])]
class Holding
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    /**
     * One entry per slot, with its own accrual anchor.
     *
     * JSON because slots are a small, fixed-width, always-read-whole structure
     * belonging to exactly one Holding. A normalised table would add a join to
     * every read of a row that is never queried by slot.
     *
     * @var list<array{index: int, materialId: string|null, accruedAt: int}>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $slots = [];

    /**
     * The gold tithe's anchor, and the row's "when was this last claimed".
     *
     * The tithe is not slotted (docs/idle.md section 2), so it anchors on the
     * Holding itself rather than on any slot.
     */
    #[ORM\Column(name: 'last_claimed_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $lastClaimedAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $characterId, DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->lastClaimedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function lastClaimedAt(): DateTimeImmutable
    {
        return $this->lastClaimedAt;
    }

    /**
     * The slots as they stand for a character of this level.
     *
     * Slots unlocked by levelling appear here as idle entries the moment the
     * level is reached; the stored array is only rewritten when something is
     * actually claimed or assigned, so a read never causes a write.
     *
     * @return list<ProductionSlot>
     */
    public function slots(int $level, DateTimeImmutable $now): array
    {
        return array_map(
            static fn (array $slot): ProductionSlot => new ProductionSlot(
                $slot['index'],
                $slot['materialId'],
                $slot['accruedAt'],
            ),
            $this->normalisedSlots($level, $now),
        );
    }

    /**
     * Assigns a production line to a slot, or clears it when null.
     *
     * Reassignment **discards that slot's pending accrual** (docs/idle.md
     * section 2). Deliberate, and the reason the read endpoint reports pending
     * output per slot: a player can see what a switch would cost and claim
     * first. Silently carrying the progress over would make switching lines
     * free, which would turn slot assignment from a commitment into a thing you
     * flip whenever the next tier unlocks.
     *
     * The caller is responsible for checking that the *material* is one this
     * character may produce — that depends on the content catalogue, which
     * lives outside the aggregate. What is enforced here is the slot budget,
     * which the Holding can know on its own.
     */
    public function assign(int $index, ?string $materialId, int $level, DateTimeImmutable $now): void
    {
        $unlocked = HoldingRules::slotsAt($level);

        if ($index < 0 || $index >= $unlocked) {
            throw new DomainException(sprintf(
                'Slot %d is not available: this Holding has %d slot(s).',
                $index + 1,
                $unlocked,
            ));
        }

        $slots = $this->normalisedSlots($level, $now);

        // Rewritten whole rather than patched field by field: the anchor reset
        // is not an incidental side effect of a reassignment, it *is* the rule
        // (docs/idle.md section 2), and writing both fields together is what
        // makes that impossible to half-apply.
        $slots[$index] = [
            'index' => $index,
            'materialId' => $materialId,
            'accruedAt' => $now->getTimestamp(),
        ];

        $this->slots = $slots;
        $this->updatedAt = $now;
    }

    /**
     * What a claim would produce right now, without producing it.
     *
     * The read endpoint and the claim run the same calculation over the same
     * state, so "pending" and "granted" cannot disagree.
     *
     * @param array<string, int> $baseRatesPerHour Keyed by material id.
     */
    public function project(int $level, array $baseRatesPerHour, DateTimeImmutable $now): HoldingYield
    {
        return $this->calculate($level, $baseRatesPerHour, $now)['yield'];
    }

    /**
     * Produces everything pending and advances the anchors.
     *
     * Idempotent in the way that matters: claiming twice in a row yields
     * nothing the second time, because the anchors have already moved. The
     * caller still needs the row lock — idempotence here is about *sequential*
     * claims, not concurrent ones.
     *
     * @param array<string, int> $baseRatesPerHour Keyed by material id.
     */
    public function claim(int $level, array $baseRatesPerHour, DateTimeImmutable $now): HoldingYield
    {
        $result = $this->calculate($level, $baseRatesPerHour, $now);

        $this->slots = $result['slots'];
        $this->lastClaimedAt = $now->setTimestamp($result['titheAnchoredAt']);
        $this->updatedAt = $now;

        return $result['yield'];
    }

    /**
     * @param array<string, int> $baseRatesPerHour
     *
     * @return array{
     *     yield: HoldingYield,
     *     slots: list<array{index: int, materialId: string|null, accruedAt: int}>,
     *     titheAnchoredAt: int,
     * }
     */
    private function calculate(int $level, array $baseRatesPerHour, DateTimeImmutable $now): array
    {
        $capSeconds = HoldingRules::capSecondsAt($level);
        $timestamp = $now->getTimestamp();

        $slots = $this->normalisedSlots($level, $now);
        $materials = [];

        foreach ($slots as $index => $slot) {
            $materialId = $slot['materialId'];

            // An unassigned slot, or one assigned to a line this character can
            // no longer produce, accrues nothing. The second case is not
            // hypothetical: a material can be withdrawn from content, and the
            // slot must then be inert rather than crash a claim.
            $rate = $materialId === null
                ? 0
                : HoldingRules::ratePerHour($baseRatesPerHour[$materialId] ?? 0);

            $accrued = HoldingRules::accrue($rate, $slot['accruedAt'], $timestamp, $capSeconds);

            $slots[$index]['accruedAt'] = $accrued['anchoredAt'];

            if ($materialId !== null && $accrued['produced'] > 0) {
                $materials[$materialId] = ($materials[$materialId] ?? 0) + $accrued['produced'];
            }
        }

        $tithe = HoldingRules::accrue(
            HoldingRules::tithePerHour($level),
            $this->lastClaimedAt->getTimestamp(),
            $timestamp,
            $capSeconds,
        );

        ksort($materials, SORT_STRING);

        return [
            'yield' => new HoldingYield(
                $materials,
                $tithe['produced'],
                min($capSeconds, max(0, $timestamp - $this->lastClaimedAt->getTimestamp())),
            ),
            'slots' => $slots,
            'titheAnchoredAt' => $tithe['anchoredAt'],
        ];
    }

    /**
     * The stored slots, extended with any slot this level has since unlocked.
     *
     * A newly unlocked slot starts idle and anchored at now. Anchoring it at
     * the Holding's creation would hand a levelling player a free backlog on
     * every unlock, which is time they did not wait.
     *
     * @return list<array{index: int, materialId: string|null, accruedAt: int}>
     */
    private function normalisedSlots(int $level, DateTimeImmutable $now): array
    {
        $slots = $this->slots;
        $unlocked = HoldingRules::slotsAt($level);

        for ($index = count($slots); $index < $unlocked; ++$index) {
            $slots[$index] = [
                'index' => $index,
                'materialId' => null,
                'accruedAt' => $now->getTimestamp(),
            ];
        }

        return array_values($slots);
    }
}
