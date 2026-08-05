<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Content;

use App\Feature\Inventory\Domain\Model\MaterialDefinition;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlMaterialRepository implements MaterialRepository, ContentProvider
{
    private const string DIRECTORY = 'materials';
    private const string SCHEMA = 'material';

    /** @var array<string, MaterialDefinition>|null */
    private ?array $materials = null;

    public function __construct(private readonly ContentSource $source)
    {
    }

    public function all(): array
    {
        return $this->materials ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): MaterialDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown material "%s".', $id));
    }

    public function producibleAtLevel(int $level): array
    {
        return array_filter(
            $this->all(),
            static fn (MaterialDefinition $material): bool => $material->isProducibleAt($level),
        );
    }

    public function contentName(): string
    {
        return 'materials';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        $producible = 0;

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $material = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            if ($material->isProducibleAt(1)) {
                ++$producible;
            }
        }

        // A Holding with no assignable line at level 1 is an empty screen for
        // every new character, and the idle layer would produce nothing until
        // some later level nobody documented.
        if ($issues === [] && $producible === 0) {
            $issues[] = new ContentIssue(
                self::DIRECTORY,
                '',
                'No material is producible at level 1, so a new character\'s Holding could produce nothing.',
            );
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): MaterialDefinition
    {
        /** @var array{ratePerHour: int, unlockLevel: int}|null $production */
        $production = $raw['production'] ?? null;

        return new MaterialDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            tier: (int) $raw['tier'],
            icon: (string) $raw['icon'],
            ratePerHour: $production === null ? null : (int) $production['ratePerHour'],
            productionUnlockLevel: $production === null ? null : (int) $production['unlockLevel'],
        );
    }
}
