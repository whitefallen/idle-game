<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Event\CharacterRespecced;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Repository\ItemInstanceRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Event\DomainEventDispatcher;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Resets every allocated attribute and returns the points, for gold.
 *
 * Always available: no cooldown, no frequency limit, no confirmation the server
 * enforces. The gold cost is the only brake, which is the design decision
 * docs/progression.md section 3 and docs/economy.md section 4 both rest on —
 * build iteration is meant to be the fun part, so anything that makes respec
 * feel like a punishment is working against the game.
 *
 * The whole thing commits as one transaction: the gold, the attributes and
 * whatever subscribers do about the reset move together. A respec that charged
 * and did not reallocate, or reallocated and left illegal gear on, would both
 * be player-visible corruption.
 *
 * This handler does not know that gear comes off — Inventory subscribes to
 * CharacterRespecced and decides that (ADR-0007). What comes back in the
 * outcome is read from Inventory's equipped set, not returned by the
 * subscriber.
 *
 * Cost is read from the level *at the time of the call*, never from the
 * request. See docs/architecture.md section 5 on server authority.
 */
final class RespecHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly CharacterStats $stats,
        private readonly ItemInstanceRepository $items,
        private readonly DomainEventDispatcher $events,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(Uuid $accountId, Uuid $characterId): RespecOutcome
    {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId): RespecOutcome {
                // Locked for update, and first — the same lock order every
                // other writer takes. Without it two concurrent respecs both
                // read the same gold balance and both charge for it.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $cost = ProgressionRules::respecCost($character->level());

                if ($character->gold() < $cost) {
                    throw ApiException::of(
                        ErrorCode::InsufficientGold,
                        sprintf('Respeccing costs %d gold.', $cost),
                        ['required' => $cost, 'available' => $character->gold()],
                    );
                }

                $now = $this->clock->now();

                // Captured before the announcement, so the equipped set can be
                // diffed against what survives it.
                $equippedBefore = $this->items->findEquippedByCharacter($character->id());

                $character->spendGold($cost, $now);
                $character->respec($this->stats->equipmentOf($character->id()), $now);

                // Announced after the reset, not before: a subscriber deciding
                // what this character may still wear needs the new attributes.
                //
                // This handler does not know that gear comes off. Inventory
                // subscribes and decides that for itself (ADR-0007), which is
                // why the result has to be read back rather than returned.
                $this->events->dispatch(new CharacterRespecced(
                    characterId: $character->id(),
                    level: $character->level(),
                    attributes: $character->attributes()->toArray(),
                ));

                // Flushed before the read-back, or the query returns the rows
                // as they were and the diff comes out empty — the same reason
                // UnequipItemHandler flushes before recomputing its score.
                $this->transactions->commit();

                $unequipped = $this->noLongerEquipped($equippedBefore, $character->id());

                // Stripping gear changes the equipment contribution, so the
                // advisory score has to be recomputed a second time or it ranks
                // the player by items they are no longer wearing (ADR-0006).
                if ($unequipped !== []) {
                    $this->transactions->commit();
                    $character->updatePowerScore($this->stats->equipmentOf($character->id()));
                }

                $this->audit->record(
                    AuditAction::CharacterRespecced,
                    [
                        'gold' => ['spent' => $cost, 'balance' => $character->gold()],
                        'unspentPoints' => $character->unspentPoints(),
                        'unequipped' => array_map(
                            static fn (ItemInstance $i): string => $i->id()->toRfc4122(),
                            $unequipped,
                        ),
                    ],
                    $accountId,
                    $character->id(),
                );

                return new RespecOutcome($character, $cost, $unequipped);
            },
        );
    }

    /**
     * What was equipped before the respec and is not equipped after it.
     *
     * The subscriber cannot hand its result back (ADR-0007), so the effect is
     * observed instead of returned: read Inventory's published set again and
     * diff. Comparing ids rather than object identity keeps this correct
     * whether or not the two reads hand back the same instances.
     *
     * @param list<ItemInstance> $before
     *
     * @return list<ItemInstance>
     */
    private function noLongerEquipped(array $before, Uuid $characterId): array
    {
        if ($before === []) {
            return [];
        }

        $after = [];

        foreach ($this->items->findEquippedByCharacter($characterId) as $item) {
            $after[$item->id()->toRfc4122()] = true;
        }

        return array_values(array_filter(
            $before,
            static fn (ItemInstance $item): bool => !isset($after[$item->id()->toRfc4122()]),
        ));
    }
}
