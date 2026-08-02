<?php

declare(strict_types=1);

namespace App\Feature\Combat\Infrastructure\Content;

use App\Feature\Combat\Domain\Model\Ability;
use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\TargetSelector;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlAbilityRepository implements AbilityRepository, ContentProvider
{
    private const string DIRECTORY = 'abilities';
    private const string SCHEMA = 'ability';

    /** @var array<string, Ability>|null */
    private ?array $abilities = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly EffectRepository $effects,
    ) {
    }

    public function all(): array
    {
        return $this->abilities ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): Ability
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown ability "%s".', $id));
    }

    public function subset(array $ids): array
    {
        $all = $this->all();
        $subset = [];

        foreach ($ids as $id) {
            $subset[$id] = $all[$id]
                ?? throw new InvalidArgumentException(sprintf('Unknown ability "%s".', $id));
        }

        ksort($subset, SORT_STRING);

        return $subset;
    }

    public function contentName(): string
    {
        return 'abilities';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $ability = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            // Referential integrity across content types. The schema can check
            // that an effect id is well-formed but not that it exists.
            if ($ability->effectId !== null && !$this->effects->has($ability->effectId)) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    sprintf('References unknown effect "%s".', $ability->effectId),
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): Ability
    {
        return new Ability(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            focusCost: (int) $raw['focusCost'],
            cooldownRounds: (int) $raw['cooldownRounds'],
            damageCoefficientBp: (int) $raw['damageCoefficientBp'],
            healCoefficientBp: (int) $raw['healCoefficientBp'],
            school: DamageSchool::from((string) $raw['school']),
            selector: TargetSelector::from((string) $raw['selector']),
            effectId: isset($raw['effectId']) ? (string) $raw['effectId'] : null,
            effectChanceBp: (int) ($raw['effectChanceBp'] ?? 0),
        );
    }
}
