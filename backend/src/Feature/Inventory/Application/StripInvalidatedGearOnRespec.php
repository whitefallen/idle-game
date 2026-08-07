<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Character\Domain\Event\CharacterRespecced;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Takes off gear a respec has invalidated.
 *
 * This is Inventory's business, reached without Character knowing it happens:
 * Character announces that attributes were reset, and this decides what that
 * means for equipment. Adding a second consequence of a respec — a discipline
 * that required an attribute, say — is a new subscriber rather than an edit to
 * RespecHandler.
 *
 * Runs synchronously inside the respec transaction (ADR-0007). If it throws,
 * the respec rolls back, which is correct: charging for a reallocation that
 * left illegal gear on is worse than refusing the reallocation.
 *
 * It returns nothing. RespecHandler learns what came off by reading the
 * equipped set afterwards, which is the read-model access
 * docs/architecture.md section 3.1 permits.
 */
#[AsEventListener]
final readonly class StripInvalidatedGearOnRespec
{
    public function __construct(private UnequipUnmetRequirementsHandler $unequip)
    {
    }

    public function __invoke(CharacterRespecced $event): void
    {
        ($this->unequip)($event->characterId, $event->level, $event->attributes);
    }
}
