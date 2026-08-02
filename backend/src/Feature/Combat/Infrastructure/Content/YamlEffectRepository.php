<?php

declare(strict_types=1);

namespace App\Feature\Combat\Infrastructure\Content;

use App\Feature\Combat\Domain\Model\DamageSchool;
use App\Feature\Combat\Domain\Model\EffectDefinition;
use App\Feature\Combat\Domain\Model\EffectKind;
use App\Feature\Combat\Domain\Repository\EffectRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlEffectRepository implements EffectRepository, ContentProvider
{
    private const string DIRECTORY = 'effects';
    private const string SCHEMA = 'effect';

    /** @var array<string, EffectDefinition>|null */
    private ?array $effects = null;

    public function __construct(private readonly ContentSource $source)
    {
    }

    public function all(): array
    {
        return $this->effects ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): EffectDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown effect "%s".', $id));
    }

    public function contentName(): string
    {
        return 'effects';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        // The schema cannot express the value object's own invariants, so
        // construction is the real check: if every definition builds, the
        // content is genuinely usable.
        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): EffectDefinition
    {
        return new EffectDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            kind: EffectKind::from((string) $raw['kind']),
            magnitude: (int) $raw['magnitude'],
            durationRounds: (int) $raw['durationRounds'],
            school: DamageSchool::from((string) ($raw['school'] ?? DamageSchool::Physical->value)),
        );
    }
}
