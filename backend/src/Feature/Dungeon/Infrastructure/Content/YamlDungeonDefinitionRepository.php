<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Infrastructure\Content;

use App\Feature\Dungeon\Domain\Model\DungeonDefinition;
use App\Feature\Dungeon\Domain\Repository\DungeonDefinitionRepository;
use App\Feature\Encounter\Domain\Repository\EncounterDefinitionRepository;
use App\Feature\Inventory\Domain\Repository\DropTableRepository;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlDungeonDefinitionRepository implements DungeonDefinitionRepository, ContentProvider
{
    private const string DIRECTORY = 'dungeons';
    private const string SCHEMA = 'dungeon';

    /** @var array<string, DungeonDefinition>|null */
    private ?array $dungeons = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly EncounterDefinitionRepository $encounters,
        private readonly MaterialRepository $materials,
        private readonly DropTableRepository $dropTables,
    ) {
    }

    public function all(): array
    {
        return $this->dungeons ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): DungeonDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown dungeon "%s".', $id));
    }

    public function contentName(): string
    {
        return 'dungeons';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $dungeon = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach (array_keys($dungeon->cost) as $materialId) {
                if (!$this->materials->has($materialId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('Cost references unknown material "%s".', $materialId),
                    );
                }
            }

            foreach ($dungeon->encounterIds as $encounterId) {
                if (!$this->encounters->has($encounterId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('References unknown encounter "%s".', $encounterId),
                    );
                }
            }

            if ($dungeon->dropTableId !== null && !$this->dropTables->has($dungeon->dropTableId)) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    sprintf('References unknown drop table "%s".', $dungeon->dropTableId),
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): DungeonDefinition
    {
        /** @var array{xp: int, gold: int} $bonus */
        $bonus = $raw['completionBonus'];

        /** @var list<string> $encounterIds */
        $encounterIds = array_values((array) $raw['encounters']);

        return new DungeonDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            requiredLevel: (int) $raw['requiredLevel'],
            cost: array_map(intval(...), (array) $raw['cost']),
            repeatable: (bool) $raw['repeatable'],
            encounterIds: $encounterIds,
            completionBonusExperience: (int) $bonus['xp'],
            completionBonusGold: (int) $bonus['gold'],
            dropTableId: isset($raw['dropTable']) ? (string) $raw['dropTable'] : null,
        );
    }
}
