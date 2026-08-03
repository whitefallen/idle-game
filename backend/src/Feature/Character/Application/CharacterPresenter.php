<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
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
    public function __construct(private readonly BattlePlanGrammar $grammar)
    {
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
        $stats = $character->derivedStats();

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
            'vigor' => $this->vigor($character),
        ];
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
