<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Model;

use InvalidArgumentException;

/**
 * A kill quest, as authored in content.
 *
 * A quest is an expedition, not a live-tracked fight: accepting it snapshots
 * the character, and claiming it (once durationSeconds has elapsed) resolves
 * exactly one simulated fight against these monsters using that frozen
 * snapshot. See docs/adr/0008-quest-snapshot-resolution.md.
 *
 * Rewards are fixed rather than formula-derived, per docs/economy.md
 * section 2's "Quest rewards: Fixed per quest" line.
 */
final readonly class QuestDefinition
{
    /**
     * @param list<string>        $monsterIds     The opposing side of the one
     *                                             simulated fight this quest
     *                                             resolves into.
     * @param array<string, int>  $materialRewards Keyed by material id.
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $requiredLevel,
        public int $durationSeconds,
        public array $monsterIds,
        public int $experienceReward,
        public int $goldReward,
        public array $materialRewards,
    ) {
        if ($monsterIds === []) {
            throw new InvalidArgumentException(sprintf('Quest "%s" has no monsters.', $id));
        }

        if ($durationSeconds < 1) {
            throw new InvalidArgumentException(sprintf('Quest "%s" must have a positive duration.', $id));
        }
    }
}
