<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Feature\Inventory\Domain\Repository\MaterialStackRepository;
use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * Adds materials to a character's stash.
 *
 * The single write path for materials, shared by the two things that produce
 * them: encounter drops and Holding claims. One path means the row lock, the
 * create-on-first-grant case and the deadlock-avoiding ordering are decided
 * once instead of per caller.
 *
 * Nothing is flushed here — the caller owns the transaction, so a rolled-back
 * encounter or claim grants nothing.
 */
final class GrantMaterialsHandler
{
    public function __construct(
        private readonly MaterialStackRepository $stacks,
        private readonly MaterialRepository $materials,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, int> $amounts Keyed by material id.
     *
     * @return array<string, int> What was actually granted, keyed by material
     *                            id — unknown materials and non-positive
     *                            amounts are dropped rather than recorded as
     *                            awarded.
     */
    public function __invoke(Uuid $characterId, array $amounts): array
    {
        $now = $this->clock->now();
        $granted = [];

        // Sorted so that two transactions granting an overlapping set of
        // materials take their row locks in the same order. Unordered locking
        // across two rows is the textbook deadlock, and a Holding claim and an
        // encounter drop can easily touch the same two materials at once.
        ksort($amounts, SORT_STRING);

        foreach ($amounts as $materialId => $amount) {
            if ($amount <= 0 || !$this->materials->has($materialId)) {
                continue;
            }

            $stack = $this->stacks->findForUpdate($characterId, $materialId);

            if ($stack === null) {
                $stack = new MaterialStack(
                    $this->identifiers->generate(),
                    $characterId,
                    $materialId,
                    $now,
                );

                $this->stacks->save($stack);
            }

            $stack->add($amount, $now);
            $granted[$materialId] = $amount;
        }

        return $granted;
    }
}
