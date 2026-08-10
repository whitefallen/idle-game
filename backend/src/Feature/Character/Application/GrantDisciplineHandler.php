<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\CharacterDiscipline;
use App\Feature\Character\Domain\Repository\CharacterDisciplineRepository;
use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * Grants a discipline outside the level-milestone kit — quest, dungeon or
 * reputation sourced.
 *
 * No row lock of its own: the caller is expected to already hold the
 * character locked for the duration of the surrounding transaction (every
 * caller reaches this after `findByIdForUpdate`), which is what actually
 * serialises two concurrent grants for the same character. The
 * already-owned check here is a cheap idempotency guard, not the mechanism
 * that makes this safe — `uq_character_discipline` is the real backstop.
 *
 * Nothing is flushed here — the caller owns the transaction, same reasoning
 * as GrantMaterialsHandler.
 */
final class GrantDisciplineHandler
{
    public function __construct(
        private readonly CharacterDisciplineRepository $disciplines,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return bool Whether a new grant was recorded. False means the
     *              character already owned it — a no-op, not an error.
     */
    public function __invoke(Uuid $characterId, string $disciplineId): bool
    {
        if (in_array($disciplineId, $this->disciplines->idsForCharacter($characterId), true)) {
            return false;
        }

        $this->disciplines->save(new CharacterDiscipline(
            $this->identifiers->generate(),
            $characterId,
            $disciplineId,
            $this->clock->now(),
        ));

        return true;
    }
}
