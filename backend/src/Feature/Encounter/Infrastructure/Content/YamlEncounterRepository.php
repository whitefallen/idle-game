<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Infrastructure\Content;

use App\Feature\Encounter\Domain\Model\EncounterDefinition;
use App\Feature\Encounter\Domain\Model\EncounterTier;
use App\Feature\Encounter\Domain\Repository\EncounterRepository;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlEncounterRepository implements EncounterRepository, ContentProvider
{
    private const string DIRECTORY = 'encounters';
    private const string SCHEMA = 'encounter';

    /** @var array<string, EncounterDefinition>|null */
    private ?array $encounters = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly MonsterRepository $monsters,
    ) {
    }

    public function all(): array
    {
        return $this->encounters ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): EncounterDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown encounter "%s".', $id));
    }

    public function availableAtLevel(int $level): array
    {
        return array_filter(
            $this->all(),
            static fn (EncounterDefinition $e): bool => $e->requiredLevel <= $level,
        );
    }

    public function contentName(): string
    {
        return 'encounters';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $encounter = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach ($encounter->monsterIds as $monsterId) {
                if (!$this->monsters->has($monsterId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('References unknown monster "%s".', $monsterId),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): EncounterDefinition
    {
        /** @var list<string> $monsterIds */
        $monsterIds = array_values((array) $raw['monsters']);

        return new EncounterDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            level: (int) $raw['level'],
            tier: EncounterTier::from((string) $raw['tier']),
            vigorCost: (int) $raw['vigorCost'],
            requiredLevel: (int) $raw['requiredLevel'],
            monsterIds: $monsterIds,
            dropTableId: (string) $raw['dropTable'],
        );
    }
}
