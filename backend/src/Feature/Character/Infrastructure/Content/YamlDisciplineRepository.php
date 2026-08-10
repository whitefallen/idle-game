<?php

declare(strict_types=1);

namespace App\Feature\Character\Infrastructure\Content;

use App\Feature\Character\Domain\Model\Discipline;
use App\Feature\Character\Domain\Model\DisciplineSource;
use App\Feature\Character\Domain\Repository\DisciplineRepository;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlDisciplineRepository implements DisciplineRepository, ContentProvider
{
    private const string DIRECTORY = 'disciplines';
    private const string SCHEMA = 'discipline';

    /** @var array<string, Discipline>|null */
    private ?array $disciplines = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly AbilityRepository $abilities,
    ) {
    }

    public function all(): array
    {
        return $this->disciplines ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): Discipline
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown discipline "%s".', $id));
    }

    public function availableAtLevel(int $level, array $ownedIds = []): array
    {
        return array_filter(
            $this->all(),
            static fn (Discipline $discipline): bool => $discipline->isAvailableAt($level)
                || in_array($discipline->id, $ownedIds, true),
        );
    }

    public function grantedAbilityIdsAtLevel(int $level, array $ownedIds = []): array
    {
        $abilityIds = [];

        foreach ($this->availableAtLevel($level, $ownedIds) as $discipline) {
            $abilityIds[$discipline->abilityId] = true;
        }

        return array_keys($abilityIds);
    }

    public function contentName(): string
    {
        return 'disciplines';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        /** @var array<string, string> $grantedBy */
        $grantedBy = [];

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $discipline = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            // Referential integrity across content types. The schema can check
            // that an ability id is well-formed but not that it exists.
            if (!$this->abilities->has($discipline->abilityId)) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    sprintf('Grants unknown ability "%s".', $discipline->abilityId),
                );

                continue;
            }

            // Two disciplines granting the same ability would let a player hold
            // the same ability in two slots, or reach it earlier than intended
            // through the cheaper of the two. Variants are a deliberate future
            // feature and will grant *different* abilities; an accidental
            // duplicate is a content mistake.
            if (isset($grantedBy[$discipline->abilityId])) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    sprintf(
                        'Ability "%s" is already granted by "%s".',
                        $discipline->abilityId,
                        $grantedBy[$discipline->abilityId],
                    ),
                );

                continue;
            }

            $grantedBy[$discipline->abilityId] = (string) $id;
        }

        // A character must always be able to build a legal plan, whose final
        // rule needs a free, off-cooldown ability. If no starting discipline
        // grants one, every new character is stuck at creation.
        if ($issues === [] && $this->startingFallbackAbilities() === []) {
            $issues[] = new ContentIssue(
                self::DIRECTORY,
                '',
                'No discipline available at level 1 grants a zero-cost, no-cooldown ability, '
                . 'so a new character could not form a valid battle plan.',
            );
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function startingFallbackAbilities(): array
    {
        $fallbacks = [];

        foreach ($this->grantedAbilityIdsAtLevel(1) as $abilityId) {
            if ($this->abilities->has($abilityId) && $this->abilities->get($abilityId)->isAlwaysAvailable()) {
                $fallbacks[] = $abilityId;
            }
        }

        return $fallbacks;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): Discipline
    {
        return new Discipline(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            abilityId: (string) $raw['abilityId'],
            source: DisciplineSource::from((string) $raw['source']),
            unlockLevel: isset($raw['unlockLevel']) ? (int) $raw['unlockLevel'] : null,
        );
    }
}
