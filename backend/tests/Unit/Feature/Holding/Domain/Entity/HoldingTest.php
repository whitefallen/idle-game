<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Holding\Domain\Entity;

use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Service\HoldingRules;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The aggregate, exercised without a database, a clock or a content library —
 * which is the point of passing rates and level in as arguments.
 */
#[CoversClass(Holding::class)]
final class HoldingTest extends TestCase
{
    private const string EMBERASH = 'material.emberash';

    /** @var array<string, int> */
    private const array RATES = [self::EMBERASH => 12, 'material.slagiron' => 7];

    public function testAFreshHoldingProducesNothing(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);

        $claimed = $holding->claim(1, self::RATES, $now->modify('+8 hours'));

        self::assertSame([], $claimed->materials, 'Unassigned slots produce nothing.');
    }

    public function testTheTitheAccruesWithoutASlot(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);

        $claimed = $holding->claim(1, self::RATES, $now->modify('+10 hours'));

        self::assertSame(10 * HoldingRules::tithePerHour(1), $claimed->gold);
    }

    public function testAnAssignedSlotProducesItsLine(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);

        $holding->assign(0, self::EMBERASH, 1, $now);
        $claimed = $holding->claim(1, self::RATES, $now->modify('+3 hours'));

        self::assertSame([self::EMBERASH => 36], $claimed->materials);
    }

    /**
     * Sequential idempotence: the anchors moved, so there is nothing left. This
     * is what makes a double-tapped claim button harmless even before the
     * idempotency key is considered.
     */
    public function testASecondImmediateClaimYieldsNothing(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);
        $holding->assign(0, self::EMBERASH, 1, $now);

        $later = $now->modify('+3 hours');
        $first = $holding->claim(1, self::RATES, $later);
        $second = $holding->claim(1, self::RATES, $later);

        self::assertSame(36, $first->materials[self::EMBERASH]);
        self::assertTrue($second->isEmpty(), 'Claiming twice in a row must yield nothing the second time.');
    }

    public function testProjectingDoesNotConsumeWhatItReports(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);
        $holding->assign(0, self::EMBERASH, 1, $now);

        $later = $now->modify('+3 hours');
        $projected = $holding->project(1, self::RATES, $later);
        $claimed = $holding->claim(1, self::RATES, $later);

        self::assertSame(
            $projected->materials,
            $claimed->materials,
            'What the read endpoint shows as pending must be exactly what a claim grants.',
        );
        self::assertSame($projected->gold, $claimed->gold);
    }

    /**
     * Reassignment discards that slot's pending accrual (docs/idle.md §2), and
     * discards only that slot's — the reason each slot carries its own anchor.
     */
    public function testReassigningASlotDiscardsItsPendingOutputAndNoOther(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);
        $holding->assign(0, self::EMBERASH, 1, $now);
        $holding->assign(1, self::EMBERASH, 1, $now);

        $later = $now->modify('+3 hours');
        $holding->assign(0, 'material.slagiron', 1, $later);

        $claimed = $holding->claim(1, self::RATES, $later);

        self::assertSame(
            [self::EMBERASH => 36],
            $claimed->materials,
            'The untouched slot keeps its three hours; the reassigned one starts over.',
        );
    }

    public function testASlotBeyondTheLevelLadderCannotBeAssigned(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);

        $this->expectException(DomainException::class);

        $holding->assign(2, self::EMBERASH, 1, $now);
    }

    public function testLevellingUpRevealsASlotWithoutBackdatingIt(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);

        $later = $now->modify('+3 hours');
        $slots = $holding->slots(10, $later);

        self::assertCount(3, $slots);
        self::assertSame(
            $later->getTimestamp(),
            $slots[2]->accruedAt,
            'A slot unlocked by levelling starts now, not at the Holding\'s creation.',
        );
    }

    /**
     * A material can be withdrawn from content. A slot assigned to one must go
     * inert rather than fail the claim — the player still has everything else.
     */
    public function testASlotAssignedToAWithdrawnLineProducesNothing(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);
        $holding->assign(0, 'material.retired', 1, $now);

        $claimed = $holding->claim(1, self::RATES, $now->modify('+5 hours'));

        self::assertSame([], $claimed->materials);
        self::assertGreaterThan(0, $claimed->gold, 'The rest of the Holding keeps working.');
    }

    public function testTheClaimIsCappedByLevel(): void
    {
        $now = $this->at('2026-08-04 12:00:00');
        $holding = $this->holding($now);
        $holding->assign(0, self::EMBERASH, 1, $now);

        $claimed = $holding->claim(1, self::RATES, $now->modify('+30 days'));

        self::assertSame(
            HoldingRules::capSecondsAt(1),
            $claimed->elapsedSeconds,
            'A month away is reported, and paid, as one cap.',
        );
        self::assertSame(12 * 12, $claimed->materials[self::EMBERASH]);
    }

    private function holding(DateTimeImmutable $now): Holding
    {
        return new Holding(Uuid::v7(), Uuid::v7(), $now);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
