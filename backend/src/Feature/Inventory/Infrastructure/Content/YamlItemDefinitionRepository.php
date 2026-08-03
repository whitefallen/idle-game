<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Infrastructure\Content;

use App\Feature\Inventory\Domain\Model\EquipmentSlot;
use App\Feature\Inventory\Domain\Model\ItemDefinition;
use App\Feature\Inventory\Domain\Model\WeaponClass;
use App\Feature\Inventory\Domain\Repository\AffixRepository;
use App\Feature\Inventory\Domain\Repository\ItemDefinitionRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlItemDefinitionRepository implements ItemDefinitionRepository, ContentProvider
{
    private const string DIRECTORY = 'items';
    private const string SCHEMA = 'item';

    /** @var array<string, ItemDefinition>|null */
    private ?array $items = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly AffixRepository $affixes,
    ) {
    }

    public function all(): array
    {
        return $this->items ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): ItemDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown item "%s".', $id));
    }

    public function inPool(string $pool): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ItemDefinition $item): bool => in_array($pool, $item->tags, true),
        ));
    }

    public function contentName(): string
    {
        return 'items';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        $pools = [];

        foreach ($this->affixes->all() as $affix) {
            $pools[$affix->pool] = true;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $item = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach ($item->allowedAffixPools as $pool) {
                if (!isset($pools[$pool])) {
                    // An empty pool means the item can never roll the affixes
                    // its rarity promises, which looks like a bug to a player.
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('Affix pool "%s" has no affixes in it.', $pool),
                    );
                }
            }

            if ($item->slot->isWeaponSlot() && $item->weaponClass === null) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    'A weapon must declare a weapon class, or it has no damage curve.',
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): ItemDefinition
    {
        /** @var array<string, mixed> $requirements */
        $requirements = $raw['requirements'];

        /** @var array<string, int> $attributes */
        $attributes = array_map(intval(...), (array) ($requirements['attributes'] ?? []));

        /** @var list<string> $pools */
        $pools = array_values((array) ($raw['allowedAffixPools'] ?? []));

        /** @var list<string> $tags */
        $tags = array_values((array) ($raw['tags'] ?? []));

        return new ItemDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            slot: EquipmentSlot::from((string) $raw['slot']),
            itemLevel: (int) $raw['ilvl'],
            icon: (string) $raw['icon'],
            vendorValue: (int) $raw['vendorValue'],
            requiredLevel: (int) $requirements['level'],
            attributeRequirements: $attributes,
            allowedAffixPools: $pools,
            tags: $tags,
            twoHanded: (bool) ($raw['twoHanded'] ?? false),
            weaponClass: isset($raw['weaponClass'])
                ? WeaponClass::from((string) $raw['weaponClass'])
                : null,
        );
    }
}
