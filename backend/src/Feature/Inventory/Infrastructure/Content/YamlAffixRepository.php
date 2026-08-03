<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Content;

use App\Feature\Inventory\Domain\Model\AffixDefinition;
use App\Feature\Inventory\Domain\Model\AffixKind;
use App\Feature\Inventory\Domain\Model\AffixTier;
use App\Feature\Inventory\Domain\Model\ModifierMode;
use App\Feature\Inventory\Domain\Model\ModifierStat;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlAffixRepository implements AffixRepository, ContentProvider
{
    private const string DIRECTORY = 'affixes';
    private const string SCHEMA = 'affix';

    /** @var array<string, AffixDefinition>|null */
    private ?array $affixes = null;

    public function __construct(private readonly ContentSource $source)
    {
    }

    public function all(): array
    {
        return $this->affixes ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): AffixDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown affix "%s".', $id));
    }

    public function contentName(): string
    {
        return 'affixes';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $affix = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            // A tier that never unlocks is dead content: it can be authored,
            // reviewed and shipped without ever appearing on an item.
            /** @var non-empty-list<int> $levels AffixDefinition rejects an empty tier list. */
            $levels = array_map(static fn (AffixTier $t): int => $t->minimumItemLevel, $affix->tiers);

            if (count($levels) !== count(array_unique($levels))) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    'Two tiers unlock at the same item level, so one can never be rolled.',
                );
            }

            if (min($levels) > 1 && $affix->tierFor(1) !== null) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, 'Inconsistent tier unlocks.');
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): AffixDefinition
    {
        /** @var array<string, mixed> $modifier */
        $modifier = $raw['modifier'];

        /** @var list<array<string, mixed>> $tiers */
        $tiers = $raw['tiers'];

        return new AffixDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            pool: (string) $raw['pool'],
            kind: AffixKind::from((string) $raw['kind']),
            stat: ModifierStat::from((string) $modifier['stat']),
            mode: ModifierMode::from((string) $modifier['mode']),
            tiers: array_map(
                static function (array $tier): AffixTier {
                    /** @var list<int> $roll */
                    $roll = $tier['roll'];

                    return new AffixTier(
                        (int) $tier['tier'],
                        (int) $tier['minIlvl'],
                        (int) $roll[0],
                        (int) $roll[1],
                    );
                },
                $tiers,
            ),
        );
    }
}
