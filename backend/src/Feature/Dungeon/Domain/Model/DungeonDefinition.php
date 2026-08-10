<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Model;

use InvalidArgumentException;

/**
 * A dungeon, as authored in content: existing EncounterDefinitions run in
 * sequence as one request, gated by consuming a key material on entry.
 *
 * Unlike Quest, a dungeon resolves live rather than through a duration/
 * snapshot expedition — see docs/adr/0008-quest-snapshot-resolution.md's
 * alternatives-considered section for why the two features deliberately use
 * different mechanisms.
 */
final readonly class DungeonDefinition
{
    /**
     * @param list<string> $encounterIds Run in order; a run stops at the
     *                                   first non-Victory stage.
     */
    public function __construct(
        public string $id,
        public string $localisationKey,
        public int $requiredLevel,
        public string $keyMaterialId,
        public array $encounterIds,
        public int $completionBonusExperience,
        public int $completionBonusGold,
        public ?string $dropTableId,
    ) {
        if (count($encounterIds) < 2) {
            throw new InvalidArgumentException(sprintf('Dungeon "%s" must have at least two stages.', $id));
        }
    }
}
