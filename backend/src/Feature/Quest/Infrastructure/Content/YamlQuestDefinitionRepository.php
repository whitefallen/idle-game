<?php

declare(strict_types=1);

namespace App\Feature\Quest\Infrastructure\Content;

use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Feature\Quest\Domain\Model\QuestDefinition;
use App\Feature\Quest\Domain\Repository\QuestDefinitionRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlQuestDefinitionRepository implements QuestDefinitionRepository, ContentProvider
{
    private const string DIRECTORY = 'quests';
    private const string SCHEMA = 'quest';

    /** @var array<string, QuestDefinition>|null */
    private ?array $quests = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly MonsterRepository $monsters,
        private readonly MaterialRepository $materials,
    ) {
    }

    public function all(): array
    {
        return $this->quests ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): QuestDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown quest "%s".', $id));
    }

    public function contentName(): string
    {
        return 'quests';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $quest = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach ($quest->monsterIds as $monsterId) {
                if (!$this->monsters->has($monsterId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('References unknown monster "%s".', $monsterId),
                    );
                }
            }

            foreach (array_keys($quest->materialRewards) as $materialId) {
                if (!$this->materials->has($materialId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('Rewards unknown material "%s".', $materialId),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): QuestDefinition
    {
        /** @var array{xp: int, gold: int, materials?: array<string, int>} $rewards */
        $rewards = $raw['rewards'];

        /** @var list<string> $monsterIds */
        $monsterIds = array_values((array) $raw['monsters']);

        return new QuestDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            requiredLevel: (int) $raw['requiredLevel'],
            durationSeconds: (int) $raw['durationSeconds'],
            monsterIds: $monsterIds,
            experienceReward: (int) $rewards['xp'],
            goldReward: (int) $rewards['gold'],
            materialRewards: array_map(intval(...), $rewards['materials'] ?? []),
        );
    }
}
