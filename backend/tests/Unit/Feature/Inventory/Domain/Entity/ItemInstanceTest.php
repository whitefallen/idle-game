<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Inventory\Domain\Entity;

use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Service\RefinementRules;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ItemInstance::class)]
final class ItemInstanceTest extends TestCase
{
    private static function item(): ItemInstance
    {
        return new ItemInstance(
            Uuid::v7(),
            Uuid::v7(),
            'item.test_chest',
            10,
            ItemRarity::Common,
            [],
            new DateTimeImmutable('2026-08-05 00:00:00'),
        );
    }

    public function testANewItemStartsUnrefined(): void
    {
        self::assertSame(0, self::item()->refineLevel());
    }

    public function testRefiningAdvancesTheLevelByExactlyOne(): void
    {
        $item = self::item();

        $item->refine();

        self::assertSame(1, $item->refineLevel());
    }

    /**
     * There is no failure chance and nothing to roll (docs/items.md section 5)
     * — refine() either advances the level or, past the cap, refuses outright.
     * There is no third outcome.
     */
    public function testRefiningStopsAtTheCap(): void
    {
        $item = self::item();

        for ($i = 0; $i < RefinementRules::MAX_LEVEL; ++$i) {
            $item->refine();
        }

        self::assertSame(RefinementRules::MAX_LEVEL, $item->refineLevel());

        self::expectException(DomainException::class);
        $item->refine();
    }
}
