<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Persistence\TransactionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Reads characters, bringing accruing resources up to date first.
 *
 * Vigor is derived from elapsed time rather than incremented by a scheduled
 * job, so "catch up, then read" is what a read actually means. Doing it here
 * rather than in the controller keeps the write — and therefore the transaction
 * boundary — in the Application layer where it belongs.
 */
final class ViewCharacterHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @return list<Character>
     */
    public function forAccount(Uuid $accountId): array
    {
        $characters = $this->characters->findByAccount($accountId);
        $now = $this->clock->now();

        foreach ($characters as $character) {
            $character->regenerateVigor($now);
        }

        $this->transactions->commit();

        return $characters;
    }

    public function one(Uuid $accountId, Uuid $characterId): Character
    {
        $character = $this->characters->findById($characterId);

        // Ownership comes from the session, never the request. A character
        // belonging to another account is reported as absent rather than
        // forbidden, so the endpoint cannot be used to enumerate ids.
        if ($character === null || !$character->isOwnedBy($accountId)) {
            throw ApiException::notFound('Character');
        }

        $character->regenerateVigor($this->clock->now());
        $this->transactions->commit();

        return $character;
    }
}
