<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Model\HoldingYield;
use App\Feature\Holding\Domain\Model\ProductionSlot;
use App\Feature\Holding\Domain\Service\HoldingRules;
use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Model\MaterialDefinition;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * The Holding read model.
 *
 * Accruing values are returned as **server timestamps plus rates**, never as a
 * client-interpolatable number alone, so the UI can render a live countdown
 * from one payload without polling and without trusting its own clock
 * (docs/api.md section 7).
 *
 * Locked slots and locked lines are included rather than filtered out. A
 * progression axis a player cannot see ahead of is one they cannot plan around,
 * which is the same reason the discipline catalogue ships its locked entries.
 */
final class HoldingPresenter
{
    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly MaterialStackRepository $stacks,
        private readonly ProductionRates $rates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Holding $holding, Uuid $characterId, int $level, DateTimeImmutable $now): array
    {
        $rates = $this->rates->perHour();
        $capSeconds = HoldingRules::capSecondsAt($level);

        return [
            'cap_seconds' => $capSeconds,
            'slots_unlocked' => HoldingRules::slotsAt($level),
            'max_slots' => HoldingRules::MAX_SLOTS,
            'last_claimed_at' => $holding->lastClaimedAt()->format(DATE_RFC3339),
            'slots' => $this->slots($holding, $rates, $level, $capSeconds, $now),
            'tithe' => $this->tithe($holding, $level, $capSeconds, $now),
            'pending' => $this->pending($holding->project($level, $rates, $now)),
            'lines' => $this->lines($level),
            'stash' => $this->stash($characterId),
        ];
    }

    /**
     * Every slot the Holding will ever have, locked ones included.
     *
     * The locked entries are built here rather than synthesised by the client,
     * because the level each one unlocks at is a game rule and the client is
     * not allowed to know one. A client that invents its own placeholders
     * invents their requirements too, and gets them wrong.
     *
     * @param array<string, int> $rates
     *
     * @return list<array<string, mixed>>
     */
    private function slots(
        Holding $holding,
        array $rates,
        int $level,
        int $capSeconds,
        DateTimeImmutable $now,
    ): array {
        $slots = [];

        foreach ($holding->slots($level, $now) as $slot) {
            $slots[] = $this->slot($slot, $rates, $level, $capSeconds, $now);
        }

        for ($index = count($slots); $index < HoldingRules::MAX_SLOTS; ++$index) {
            $slots[] = $this->slot(
                new ProductionSlot($index, null, $now->getTimestamp()),
                $rates,
                $level,
                $capSeconds,
                $now,
            );
        }

        return $slots;
    }

    /**
     * @param array<string, int> $rates
     *
     * @return array<string, mixed>
     */
    private function slot(
        ProductionSlot $slot,
        array $rates,
        int $level,
        int $capSeconds,
        DateTimeImmutable $now,
    ): array {
        $ratePerHour = $slot->materialId === null
            ? 0
            : HoldingRules::ratePerHour($rates[$slot->materialId] ?? 0);

        $accrued = HoldingRules::accrue($ratePerHour, $slot->accruedAt, $now->getTimestamp(), $capSeconds);
        $elapsed = max(0, $now->getTimestamp() - $slot->accruedAt);

        return [
            'index' => $slot->index,
            'material_id' => $slot->materialId,
            'unlocked' => $slot->index < HoldingRules::slotsAt($level),
            'unlocks_at_level' => HoldingRules::slotUnlockLevel($slot->index),
            'rate_per_hour' => $ratePerHour,
            // What would be granted by a claim right now — and, equally, what a
            // reassignment would discard.
            'pending' => $accrued['produced'],
            'accrued_at' => $now->setTimestamp($slot->accruedAt)->format(DATE_RFC3339),
            'full_at' => $now->setTimestamp(HoldingRules::fullAt($slot->accruedAt, $capSeconds))
                ->format(DATE_RFC3339),
            'seconds_until_next' => HoldingRules::secondsUntilNextUnit(
                $ratePerHour,
                $slot->accruedAt,
                $now->getTimestamp(),
                $capSeconds,
            ),
            // Production stops at the cap rather than overflowing, so this is
            // the state the UI must make loud: every further hour away is
            // simply lost.
            'at_cap' => $ratePerHour > 0 && $elapsed >= $capSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tithe(Holding $holding, int $level, int $capSeconds, DateTimeImmutable $now): array
    {
        $anchoredAt = $holding->lastClaimedAt()->getTimestamp();
        $perHour = HoldingRules::tithePerHour($level);

        return [
            'gold_per_hour' => $perHour,
            'pending' => HoldingRules::accrue($perHour, $anchoredAt, $now->getTimestamp(), $capSeconds)['produced'],
            'full_at' => $now->setTimestamp(HoldingRules::fullAt($anchoredAt, $capSeconds))->format(DATE_RFC3339),
            'at_cap' => max(0, $now->getTimestamp() - $anchoredAt) >= $capSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(HoldingYield $projected): array
    {
        return [
            'gold' => $projected->gold,
            'materials' => $projected->materials,
            'elapsed_seconds' => $projected->elapsedSeconds,
        ];
    }

    /**
     * The production lines, locked ones included, in tier order.
     *
     * Tier order rather than the catalogue's id order: the tiers *are* the
     * progression, so a list sorted alphabetically would open on Cinderglass —
     * a line unlocking at level 45 — and bury the one the player can actually
     * use. Sorted here rather than in the client, because which order is
     * meaningful is a property of the content, not of the widget.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $level): array
    {
        $producible = [];

        foreach ($this->materials->all() as $material) {
            if ($material->isProducible()) {
                $producible[] = $material;
            }
        }

        usort(
            $producible,
            static fn (MaterialDefinition $a, MaterialDefinition $b): int => [$a->tier, $a->id] <=> [$b->tier, $b->id],
        );

        $lines = [];

        foreach ($producible as $material) {
            $lines[] = [
                'material_id' => $material->id,
                'localisation_key' => $material->localisationKey,
                'tier' => $material->tier,
                'icon' => $material->icon,
                'rate_per_hour' => HoldingRules::ratePerHour((int) $material->ratePerHour),
                'unlock_level' => (int) $material->productionUnlockLevel,
                'unlocked' => $material->isProducibleAt($level),
            ];
        }

        return $lines;
    }

    /**
     * The material balances, including materials the Holding cannot produce —
     * the stash is one place, whatever a material came from.
     *
     * @return list<array<string, mixed>>
     */
    private function stash(Uuid $characterId): array
    {
        $definitions = $this->materials->all();

        return array_map(
            static function (MaterialStack $stack) use ($definitions): array {
                // A stack whose definition has been withdrawn from content
                // still renders, with the id standing in for the name. The
                // balance is the player's; it does not disappear because a
                // designer retired the material.
                if (!isset($definitions[$stack->materialId()])) {
                    return [
                        'material_id' => $stack->materialId(),
                        'localisation_key' => $stack->materialId(),
                        'tier' => 0,
                        'icon' => 'material_unknown',
                        'quantity' => $stack->quantity(),
                    ];
                }

                $definition = $definitions[$stack->materialId()];

                return [
                    'material_id' => $stack->materialId(),
                    'localisation_key' => $definition->localisationKey,
                    'tier' => $definition->tier,
                    'icon' => $definition->icon,
                    'quantity' => $stack->quantity(),
                ];
            },
            $this->stacks->findByCharacter($characterId),
        );
    }
}
