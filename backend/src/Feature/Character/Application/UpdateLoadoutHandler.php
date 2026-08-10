<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterDisciplineRepository;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Changes which disciplines a character has slotted.
 *
 * Free and uncooled, like editing a battle plan: owning a discipline is
 * permanent and slotting is the limited resource, so experimenting with a
 * loadout is meant to be the enjoyable part rather than something a player
 * rations. See docs/progression.md section 4.1.
 *
 * The server is authoritative about what a character has unlocked. The client
 * sends ability ids and nothing else — never the discipline list it believes it
 * owns, and never its own level.
 */
final class UpdateLoadoutHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly DisciplineRepository $disciplines,
        private readonly CharacterDisciplineRepository $ownedDisciplines,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param list<string> $abilityIds
     */
    public function __invoke(Uuid $accountId, Uuid $characterId, array $abilityIds): Character
    {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $abilityIds): Character {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $this->assertUnlocked($character, $abilityIds);

                try {
                    $character->changeLoadout($abilityIds, $this->clock->now());
                } catch (DomainException $e) {
                    // Slot budget, duplicates, or a battle plan rule left
                    // stranded. All of these are ordinary player mistakes made
                    // in an editor, not defects.
                    throw ApiException::of(
                        ErrorCode::ValidationFailed,
                        $e->getMessage(),
                        ['slots' => $character->loadoutSlots(), 'requested' => count($abilityIds)],
                    );
                }

                return $character;
            },
        );
    }

    /**
     * @param list<string> $abilityIds
     */
    private function assertUnlocked(Character $character, array $abilityIds): void
    {
        $ownedIds = $this->ownedDisciplines->idsForCharacter($character->id());
        $granted = $this->disciplines->grantedAbilityIdsAtLevel($character->level(), $ownedIds);
        $locked = array_values(array_diff($abilityIds, $granted));

        if ($locked === []) {
            return;
        }

        // Structured, so the UI can name the offending entries rather than
        // rejecting the whole submission with an opaque message. A gate that
        // cannot say what is missing is treated as a bug — see
        // docs/progression.md section 5.
        throw ApiException::of(
            ErrorCode::RequirementNotMet,
            'That loadout includes abilities this character has not unlocked.',
            ['locked_abilities' => $locked, 'character_level' => $character->level()],
        );
    }
}
