<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterDisciplineRepository;
use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Character\Domain\Service\VigorRules;
use App\Feature\Combat\Application\BattlePlanGrammar;

/**
 * Renders a character for the API.
 *
 * Accruing resources are returned as a value plus its rate and the moment it
 * will be full, so the client can render a live countdown from a single
 * response without polling and without consulting its own clock.
 * See docs/api.md section 7.
 */
final class CharacterPresenter
{
    public function __construct(
        private readonly BattlePlanGrammar $grammar,
        private readonly CharacterStats $stats,
        private readonly DisciplineRepository $disciplines,
        private readonly CharacterDisciplineRepository $ownedDisciplines,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Character $character): array
    {
        return [
            'id' => $character->id()->toRfc4122(),
            'name' => $character->name(),
            'level' => $character->level(),
            'power_score' => $character->powerScore(),
            'vigor' => $this->vigor($character),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Character $character): array
    {
        $stats = $this->stats->forCharacter($character);

        return [
            'id' => $character->id()->toRfc4122(),
            'name' => $character->name(),
            'level' => $character->level(),
            'experience' => $character->experience(),
            'experience_to_next_level' => $character->experienceToNextLevel(),
            'gold' => $character->gold(),
            'emberdust' => $character->emberdust(),
            'unspent_points' => $character->unspentPoints(),
            'power_score' => $character->powerScore(),
            'attributes' => $character->attributes()->toArray(),
            // Computed on read, never stored. See ADR-0006 for the one exception.
            'derived_stats' => $stats->toArray(),
            'loadout_slots' => ProgressionRules::loadoutSlotsAt($character->level()),
            'respec_cost' => ProgressionRules::respecCost($character->level()),
            'ability_ids' => $character->abilityIds(),
            // Full metadata, not just ids: the editor needs Focus cost and
            // cooldown to show which abilities can serve as the fallback, and
            // the combat log needs them to explain why a rule fell through.
            'abilities' => $this->grammar->describeAbilities($character->abilityIds()),
            'battle_plan' => $character->battlePlan()->toArray(),
            'disciplines' => $this->disciplines($character),
            'vigor' => $this->vigor($character),
        ];
    }

    /**
     * The whole discipline catalogue, not merely the unlocked part.
     *
     * Showing what is still locked and the level it arrives at is the point: a
     * progression axis the player cannot see ahead of is one they cannot plan
     * around, and docs/progression.md section 5 treats an unexplained "not yet"
     * as a bug. The locked entries are what make the next few levels legible.
     *
     * @return list<array<string, mixed>>
     */
    private function disciplines(Character $character): array
    {
        $slotted = $character->abilityIds();
        $ownedIds = $this->ownedDisciplines->idsForCharacter($character->id());
        $entries = [];

        foreach ($this->disciplines->all() as $discipline) {
            $entries[] = [
                'id' => $discipline->id,
                'localisation_key' => $discipline->localisationKey,
                'ability_id' => $discipline->abilityId,
                'source' => $discipline->source->value,
                'unlock_level' => $discipline->unlockLevel,
                'unlocked' => $discipline->isAvailableAt($character->level())
                    || in_array($discipline->id, $ownedIds, true),
                'slotted' => in_array($discipline->abilityId, $slotted, true),
            ];
        }

        // Ordered by the level they arrive at, then by id, so the list reads as
        // a progression rather than as a hash order.
        usort($entries, static function (array $a, array $b): int {
            /** @var array{unlock_level: int|null, id: string} $a */
            /** @var array{unlock_level: int|null, id: string} $b */
            return ($a['unlock_level'] ?? PHP_INT_MAX) <=> ($b['unlock_level'] ?? PHP_INT_MAX)
                ?: strcmp($a['id'], $b['id']);
        });

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function vigor(Character $character): array
    {
        return [
            'current' => $character->vigor(),
            'max' => VigorRules::CAP,
            'seconds_per_point' => VigorRules::SECONDS_PER_POINT,
            'ticked_at' => $character->vigorTickedAt()->format(DATE_RFC3339),
            'full_at' => $character->vigorFullAt()->format(DATE_RFC3339),
        ];
    }
}
