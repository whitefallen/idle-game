<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Quest\Domain\Entity;

use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Quest\Domain\Entity\QuestRun;
use App\Feature\Quest\Domain\Entity\QuestRunStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Quest resolution state machine cannot be exercised deterministically
 * through the API — there is no test seam that pins a combat outcome, and
 * this project's convention (see EncounterFlowTest) is to prove win/loss
 * behaviour through guaranteed content match-ups rather than by injecting a
 * fixed seed. Kill quest content only has guaranteed-win match-ups today, so
 * the Failed and re-accept paths are proven here instead, directly against
 * the entity, with a synthetic CombatLog standing in for a lost fight.
 */
#[CoversClass(QuestRun::class)]
final class QuestRunTest extends TestCase
{
    private const string QUEST_ID = 'quest.stretch1.blightling_watch';

    public function testAWinIsClaimedAndTerminal(): void
    {
        $run = $this->newRun();
        $now = new DateTimeImmutable();

        $run->resolve($this->log(Outcome::Victory), 42, ['experience' => 20, 'gold' => 15], $now);

        self::assertSame(QuestRunStatus::Claimed, $run->status());
        self::assertSame(Outcome::Victory, $run->outcome());
        self::assertSame(['experience' => 20, 'gold' => 15], $run->rewards());
        self::assertSame($now, $run->resolvedAt());
    }

    public function testALossIsFailedNotTerminal(): void
    {
        $run = $this->newRun();

        $run->resolve($this->log(Outcome::Defeat), 42, [], new DateTimeImmutable());

        self::assertSame(QuestRunStatus::Failed, $run->status());
        self::assertSame(Outcome::Defeat, $run->outcome());
    }

    public function testADrawIsFailedTheSameAsALoss(): void
    {
        $run = $this->newRun();

        $run->resolve($this->log(Outcome::Draw), 42, [], new DateTimeImmutable());

        self::assertSame(QuestRunStatus::Failed, $run->status());
    }

    public function testAFailedRunMayBeReaccepted(): void
    {
        $run = $this->newRun();
        $run->resolve($this->log(Outcome::Defeat), 1, [], new DateTimeImmutable());

        $freshSnapshot = ['rulesetVersion' => 'x', 'participant' => ['id' => 'second-attempt']];
        $acceptedAt = new DateTimeImmutable('+1 hour');
        $completesAt = $acceptedAt->modify('+60 seconds');

        $run->reaccept($freshSnapshot, $acceptedAt, $completesAt);

        self::assertSame(QuestRunStatus::Active, $run->status());
        self::assertSame($freshSnapshot, $run->snapshot());
        self::assertSame($acceptedAt, $run->acceptedAt());
        self::assertSame($completesAt, $run->completesAt());
        // A fresh attempt has no result yet — the previous loss's outcome
        // must not leak into the new one.
        self::assertNull($run->outcome());
        self::assertNull($run->rewards());
        self::assertNull($run->resolvedAt());
    }

    public function testAClaimedRunRefusesReaccept(): void
    {
        $run = $this->newRun();
        $run->resolve($this->log(Outcome::Victory), 1, ['experience' => 1, 'gold' => 1], new DateTimeImmutable());

        $this->expectException(DomainException::class);

        $run->reaccept(['rulesetVersion' => 'x', 'participant' => []], new DateTimeImmutable(), new DateTimeImmutable());
    }

    public function testAnActiveRunRefusesReaccept(): void
    {
        $run = $this->newRun();

        $this->expectException(DomainException::class);

        $run->reaccept(['rulesetVersion' => 'x', 'participant' => []], new DateTimeImmutable(), new DateTimeImmutable());
    }

    public function testContentChangeLeavesTheRunFailedWithNoResult(): void
    {
        $run = $this->newRun();
        $now = new DateTimeImmutable();

        $run->markContentChanged($now);

        self::assertSame(QuestRunStatus::Failed, $run->status());
        self::assertNull($run->outcome());
        self::assertNull($run->rewards());
        self::assertNull($run->seed());
        self::assertSame($now, $run->resolvedAt());

        // Nothing was spent to reach this state, so it must be re-acceptable
        // exactly like a lost fight.
        $run->reaccept(['rulesetVersion' => 'x', 'participant' => []], new DateTimeImmutable(), new DateTimeImmutable());
        self::assertSame(QuestRunStatus::Active, $run->status());
    }

    public function testIsReadyToClaimRequiresBothActiveStatusAndElapsedTime(): void
    {
        $acceptedAt = new DateTimeImmutable();
        $completesAt = $acceptedAt->modify('+60 seconds');
        $run = new QuestRun(Uuid::v7(), Uuid::v7(), self::QUEST_ID, ['rulesetVersion' => 'x', 'participant' => []], $acceptedAt, $completesAt);

        self::assertFalse($run->isReadyToClaim($acceptedAt), 'Not ready before the timer elapses.');
        self::assertTrue($run->isReadyToClaim($completesAt), 'Ready the instant the timer elapses.');

        $run->resolve($this->log(Outcome::Victory), 1, ['experience' => 1, 'gold' => 1], $completesAt);

        self::assertFalse($run->isReadyToClaim($completesAt->modify('+1 hour')), 'A claimed run is never ready again.');
    }

    private function newRun(): QuestRun
    {
        $acceptedAt = new DateTimeImmutable();

        return new QuestRun(
            Uuid::v7(),
            Uuid::v7(),
            self::QUEST_ID,
            ['rulesetVersion' => 'x', 'participant' => ['id' => 'first-attempt']],
            $acceptedAt,
            $acceptedAt->modify('+60 seconds'),
        );
    }

    private function log(Outcome $outcome): CombatLog
    {
        return new CombatLog('x', 1, [], [], $outcome, 3);
    }
}
