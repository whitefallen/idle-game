<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Encounter\Domain\Service;

use App\Feature\Combat\Domain\Model\Outcome;
use App\Feature\Encounter\Domain\Service\RewardRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The "cost without completion" rule (game-bible.md section 9): only a
 * Victory pays out, and only a Draw refunds the Vigor already spent. No
 * content match-up guarantees a loss (see EncounterFlowTest), so this proves
 * the gate directly against the pure decision rather than through a real
 * fight.
 */
#[CoversClass(RewardRules::class)]
final class RewardRulesTest extends TestCase
{
    public function testOnlyVictoryIsPayable(): void
    {
        self::assertTrue(RewardRules::isPayable(Outcome::Victory));
        self::assertFalse(RewardRules::isPayable(Outcome::Defeat));
        self::assertFalse(RewardRules::isPayable(Outcome::Draw));
    }

    public function testOnlyADrawRefundsVigor(): void
    {
        self::assertSame(0, RewardRules::vigorRefund(Outcome::Victory, 10));
        self::assertSame(0, RewardRules::vigorRefund(Outcome::Defeat, 10));
        self::assertSame(10, RewardRules::vigorRefund(Outcome::Draw, 10));
    }
}
