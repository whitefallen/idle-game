<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Service\RefinementRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure formula tests, exercised without a database or a content library —
 * exactly the point of RefinementRules taking item level and refine level as
 * arguments rather than reading them from an entity.
 */
#[CoversClass(RefinementRules::class)]
final class RefinementRulesTest extends TestCase
{
    public function testGoldCostMatchesTheDocumentedFormula(): void
    {
        // 40 * ilvl * (refineLevel + 1)^2.
        self::assertSame(200, RefinementRules::goldCost(5, 0));
        self::assertSame(3200, RefinementRules::goldCost(5, 3));
    }

    public function testMaterialCostMatchesTheDocumentedFormula(): void
    {
        // ceil(ilvl / 5) * (refineLevel + 1).
        self::assertSame(1, RefinementRules::materialCost(5, 0));
        self::assertSame(2, RefinementRules::materialCost(6, 0), 'ceil(6 / 5) = 2.');
        self::assertSame(6, RefinementRules::materialCost(6, 2));
    }

    /**
     * docs/items.md section 5 states that refining a level-30 item from +0 to
     * +10 costs roughly 460,000 gold. Summing the per-level cost across all ten
     * steps is the closest thing to a worked example the design doc gives, so
     * it is worth pinning as a regression check on the formula as a whole.
     */
    public function testALevel30ItemsFullRefinementCostsRoughly460000Gold(): void
    {
        $total = 0;

        for ($level = 0; $level < RefinementRules::MAX_LEVEL; ++$level) {
            $total += RefinementRules::goldCost(30, $level);
        }

        self::assertSame(462_000, $total);
    }

    /**
     * The bands mirror the affix tier thresholds in docs/items.md section 4.1
     * (minIlvl 1/15/30/45), which are themselves the levels the tiered
     * materials unlock production at (content/materials/core.yaml).
     */
    public function testMaterialTierFollowsTheAffixTierBands(): void
    {
        self::assertSame(1, RefinementRules::materialTierForItemLevel(1));
        self::assertSame(1, RefinementRules::materialTierForItemLevel(14));
        self::assertSame(2, RefinementRules::materialTierForItemLevel(15));
        self::assertSame(2, RefinementRules::materialTierForItemLevel(29));
        self::assertSame(3, RefinementRules::materialTierForItemLevel(30));
        self::assertSame(3, RefinementRules::materialTierForItemLevel(44));
        self::assertSame(4, RefinementRules::materialTierForItemLevel(45));
        self::assertSame(4, RefinementRules::materialTierForItemLevel(60));
    }
}
