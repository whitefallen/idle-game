<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Model;

use InvalidArgumentException;

/**
 * A dungeon, as authored in content: existing EncounterDefinitions run in
 * sequence as one request, gated by consuming a material cost on entry.
 *
 * Two archetypes share this one definition rather than forking into separate
 * features — see docs/dungeons.md section 2: `repeatable: false` is the
 * one-time, discipline-granting kind (`cost` is typically a single quest-earned
 * key), `repeatable: true` is the grindable materials sink (`cost` is
 * typically several ordinary materials).
 *
 * Unlike Quest, a dungeon resolves live rather than through a duration/
 * snapshot expedition — see docs/adr/0008-quest-snapshot-resolution.md's
 * alternatives-considered section for why the two features deliberately use
 * different mechanisms.
 */
final readonly class DungeonDefinition
{
    /**
     * @param array<string, int> $cost         Material id to quantity, consumed
     *                                         in full or not at all on entry.
     * @param list<string>       $encounterIds Run in order; a run stops at the
     *                                         first non-Victory stage.
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $requiredLevel,
        public array $cost,
        /**
         * false is the one-time, discipline-granting archetype: entry is
         * refused once this character has already cleared it. true is the
         * repeatable, material-sink archetype. See docs/dungeons.md section 2.
         */
        public bool $repeatable,
        public array $encounterIds,
        public int $completionBonusExperience,
        public int $completionBonusGold,
        public ?string $dropTableId,
    ) {
        if (count($encounterIds) < 2) {
            throw new InvalidArgumentException(sprintf('Dungeon "%s" must have at least two stages.', $id));
        }

        if ($cost === []) {
            throw new InvalidArgumentException(sprintf('Dungeon "%s" must have an entry cost.', $id));
        }
    }
}
