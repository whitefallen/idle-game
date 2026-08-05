<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Holding\Domain\Entity\Holding;
use App\Feature\Holding\Domain\Repository\HoldingRepository;
use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * Finds a character's Holding, creating it on first write.
 *
 * Created lazily rather than at character creation, for two reasons. It keeps
 * Character free of any knowledge that the Holding feature exists — the
 * alternative is a cross-feature call in the creation path, which
 * docs/architecture.md section 3.1 forbids. And it means every character that
 * already existed before this feature shipped has a Holding the first time they
 * open one, with no backfill migration to write, run and verify.
 *
 * Lazy creation normally invites a race: two concurrent first-time requests
 * both find nothing and both insert. It cannot happen here, because every
 * caller is already holding the **character** row lock — that lock serialises
 * the two requests before either reaches this class. The unique index on
 * `character_id` is the backstop, not the mechanism.
 *
 * Reads deliberately do not provision. A Holding that has never existed has
 * nothing pending, so the read endpoint projects from a transient instance and
 * a player who only ever looks at the page never causes a write.
 */
final class HoldingProvisioner
{
    public function __construct(
        private readonly HoldingRepository $holdings,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    /** Locked for update, and created if this character has never had one. */
    public function forUpdate(Uuid $characterId): Holding
    {
        $holding = $this->holdings->findByCharacterForUpdate($characterId);

        if ($holding !== null) {
            return $holding;
        }

        $holding = new Holding($this->identifiers->generate(), $characterId, $this->clock->now());
        $this->holdings->save($holding);

        return $holding;
    }

    /**
     * Read-only. Returns a transient, unsaved Holding when none exists yet,
     * which projects to exactly the zeroes a brand new one would.
     */
    public function forRead(Uuid $characterId): Holding
    {
        return $this->holdings->findByCharacter($characterId)
            ?? new Holding($this->identifiers->generate(), $characterId, $this->clock->now());
    }
}
