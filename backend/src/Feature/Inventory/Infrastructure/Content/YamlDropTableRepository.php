<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Content;

use App\Feature\Inventory\Domain\Model\DropEntry;
use App\Feature\Inventory\Domain\Model\DropEntryKind;
use App\Feature\Inventory\Domain\Model\DropTable;
use App\Feature\Inventory\Domain\Repository\DropTableRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlDropTableRepository implements DropTableRepository, ContentProvider
{
    private const string DIRECTORY = 'droptables';
    private const string SCHEMA = 'droptable';

    /** @var array<string, DropTable>|null */
    private ?array $tables = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly ItemDefinitionRepository $items,
    ) {
    }

    public function all(): array
    {
        return $this->tables ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): DropTable
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown drop table "%s".', $id));
    }

    public function contentName(): string
    {
        return 'drop tables';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $table = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach ($table->entries as $entry) {
                if ($entry->kind !== DropEntryKind::Item || $entry->pool === null) {
                    continue;
                }

                // An empty pool means the entry silently yields nothing, which
                // reads to a player as a broken drop rate.
                if ($this->items->inPool($entry->pool) === []) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('Item pool "%s" contains no items.', $entry->pool),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): DropTable
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $raw['entries'];

        return new DropTable(
            id: (string) $raw['id'],
            rolls: (int) $raw['rolls'],
            entries: array_map(
                static function (array $entry): DropEntry {
                    /** @var list<int> $quantity */
                    $quantity = $entry['quantity'] ?? [0, 0];
                    /** @var list<int> $ilvl */
                    $ilvl = $entry['ilvlRange'] ?? [0, 0];

                    return new DropEntry(
                        weight: (int) $entry['weight'],
                        kind: DropEntryKind::from((string) $entry['type']),
                        materialId: isset($entry['materialId']) ? (string) $entry['materialId'] : null,
                        minimumQuantity: (int) $quantity[0],
                        maximumQuantity: (int) $quantity[1],
                        pool: isset($entry['pool']) ? (string) $entry['pool'] : null,
                        minimumItemLevel: (int) $ilvl[0],
                        maximumItemLevel: (int) $ilvl[1],
                    );
                },
                $entries,
            ),
        );
    }
}
