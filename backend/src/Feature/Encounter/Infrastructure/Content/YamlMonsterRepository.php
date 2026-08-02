<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Infrastructure\Content;

use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Encounter\Domain\Model\MonsterDefinition;
use App\Feature\Encounter\Domain\Repository\MonsterRepository;
use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use App\Platform\Content\ContentSource;
use InvalidArgumentException;

final class YamlMonsterRepository implements MonsterRepository, ContentProvider
{
    private const string DIRECTORY = 'monsters';
    private const string SCHEMA = 'monster';

    /** @var array<string, MonsterDefinition>|null */
    private ?array $monsters = null;

    public function __construct(
        private readonly ContentSource $source,
        private readonly AbilityRepository $abilities,
    ) {
    }

    public function all(): array
    {
        return $this->monsters ??= array_map(
            $this->map(...),
            $this->source->load(self::DIRECTORY, self::SCHEMA),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): MonsterDefinition
    {
        return $this->all()[$id]
            ?? throw new InvalidArgumentException(sprintf('Unknown monster "%s".', $id));
    }

    public function contentName(): string
    {
        return 'monsters';
    }

    public function validateContent(): array
    {
        $issues = $this->source->inspect(self::DIRECTORY, self::SCHEMA);

        if ($issues !== []) {
            return $issues;
        }

        foreach ($this->source->load(self::DIRECTORY, self::SCHEMA) as $id => $raw) {
            try {
                $monster = $this->map($raw);
            } catch (InvalidArgumentException $e) {
                $issues[] = new ContentIssue(self::DIRECTORY, (string) $id, $e->getMessage());

                continue;
            }

            foreach ($monster->abilityIds as $abilityId) {
                if (!$this->abilities->has($abilityId)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf('References unknown ability "%s".', $abilityId),
                    );
                }
            }

            // A plan naming an ability the monster does not know would throw at
            // fight time, which is the worst possible moment to discover it.
            foreach ($monster->battlePlan->rules as $index => $rule) {
                if (!in_array($rule->abilityId, $monster->abilityIds, true)) {
                    $issues[] = new ContentIssue(
                        self::DIRECTORY,
                        (string) $id,
                        sprintf(
                            'Battle plan rule %d uses ability "%s", which this monster does not know.',
                            $index + 1,
                            $rule->abilityId,
                        ),
                    );
                }
            }

            // The fallback must be genuinely unconditional and always usable,
            // otherwise a monster can reach a state where it cannot act.
            $fallback = $monster->battlePlan->rules[count($monster->battlePlan->rules) - 1];

            if ($this->abilities->has($fallback->abilityId)
                && !$this->abilities->get($fallback->abilityId)->isAlwaysAvailable()
            ) {
                $issues[] = new ContentIssue(
                    self::DIRECTORY,
                    (string) $id,
                    sprintf(
                        'Fallback ability "%s" has a Focus cost or cooldown, so the plan can stall.',
                        $fallback->abilityId,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function map(array $raw): MonsterDefinition
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = $raw['battlePlan'];

        /** @var list<string> $abilityIds */
        $abilityIds = array_values((array) $raw['abilities']);

        /** @var array<string, int> $resistances */
        $resistances = array_map(intval(...), (array) ($raw['resistanceRatings'] ?? []));

        return new MonsterDefinition(
            id: (string) $raw['id'],
            localisationKey: (string) $raw['localisationKey'],
            level: (int) $raw['level'],
            maxHealth: (int) $raw['maxHealth'],
            initiative: (int) $raw['initiative'],
            maxFocus: (int) $raw['maxFocus'],
            focusPerTurn: (int) $raw['focusPerTurn'],
            weaponBaseDamage: (int) $raw['weaponBaseDamage'],
            flatDamageBonus: (int) ($raw['flatDamageBonus'] ?? 0),
            scalingBp: (int) ($raw['scalingBp'] ?? 10000),
            critChanceBp: (int) ($raw['critChanceBp'] ?? 0),
            critPowerBp: (int) ($raw['critPowerBp'] ?? 15000),
            dodgeChanceBp: (int) ($raw['dodgeChanceBp'] ?? 0),
            accuracyBp: (int) ($raw['accuracyBp'] ?? 0),
            armourRating: (int) ($raw['armourRating'] ?? 0),
            resistanceRatings: $resistances,
            abilityIds: $abilityIds,
            battlePlan: BattlePlan::fromArray($plan),
        );
    }
}
