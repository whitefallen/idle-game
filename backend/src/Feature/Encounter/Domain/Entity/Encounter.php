<?php

declare(strict_types=1);

namespace App\Feature\Encounter\Domain\Entity;

use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Combat\Domain\Model\Outcome;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use JsonException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * A resolved encounter, stored so it can be replayed and audited.
 *
 * The four columns seed, rulesetVersion, inputSnapshot and log are what make a
 * fight reproducible years later. Storing the snapshot is non-negotiable:
 * without it, re-equipping an item silently invalidates every past fight and
 * the reproducibility claim cannot actually be checked. See ADR-0002.
 *
 * This is the highest-growth table in the schema. Rows are removed by
 * `db:retention:prune` once past the retention window; see
 * docs/data-model.md section 6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter')]
#[ORM\Index(name: 'idx_encounter_character_id', columns: ['character_id'])]
#[ORM\Index(name: 'idx_encounter_created_at', columns: ['created_at'])]
class Encounter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $definitionId;

    #[ORM\Column(type: 'bigint')]
    private int $seed;

    #[ORM\Column(type: 'string', length: 20)]
    private string $rulesetVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $inputSnapshot;

    /**
     * The combat log, gzip-compressed.
     *
     * Compressed because logs are large, written once and read rarely, and this
     * table grows faster than any other. Stored as a blob rather than JSON for
     * the same reason: nothing queries inside a log.
     */
    #[ORM\Column(type: 'blob')]
    private mixed $log;

    #[ORM\Column(type: 'string', length: 20, enumType: Outcome::class)]
    private Outcome $outcome;

    #[ORM\Column(type: 'integer')]
    private int $rounds;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $rewards;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $inputSnapshot
     * @param array<string, mixed> $rewards
     */
    public function __construct(
        Uuid $id,
        Uuid $characterId,
        string $definitionId,
        CombatLog $combatLog,
        array $inputSnapshot,
        array $rewards,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->definitionId = $definitionId;
        $this->seed = $combatLog->seed;
        $this->rulesetVersion = $combatLog->rulesetVersion;
        $this->inputSnapshot = $inputSnapshot;
        $this->outcome = $combatLog->outcome;
        $this->rounds = $combatLog->rounds;
        $this->rewards = $rewards;
        $this->createdAt = $createdAt;

        $this->log = self::compress($combatLog->toArray());
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function characterId(): Uuid
    {
        return $this->characterId;
    }

    public function belongsTo(Uuid $characterId): bool
    {
        return $this->characterId->equals($characterId);
    }

    public function definitionId(): string
    {
        return $this->definitionId;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function rulesetVersion(): string
    {
        return $this->rulesetVersion;
    }

    public function outcome(): Outcome
    {
        return $this->outcome;
    }

    public function rounds(): int
    {
        return $this->rounds;
    }

    /**
     * @return array<string, mixed>
     */
    public function rewards(): array
    {
        return $this->rewards;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSnapshot(): array
    {
        return $this->inputSnapshot;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function log(): array
    {
        // Doctrine hands back a stream for blob columns after a database read,
        // but the original string when the entity is still in memory.
        $raw = is_resource($this->log) ? (string) stream_get_contents($this->log) : (string) $this->log;

        $json = gzdecode($raw);

        if ($json === false) {
            throw new RuntimeException(sprintf('Combat log for encounter %s is corrupt.', $this->id->toRfc4122()));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Combat log for encounter %s is not valid JSON.', $this->id->toRfc4122()),
                previous: $e,
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $log
     */
    private static function compress(array $log): string
    {
        $compressed = gzencode(json_encode($log, JSON_THROW_ON_ERROR), 6);

        if ($compressed === false) {
            throw new RuntimeException('Failed to compress combat log.');
        }

        return $compressed;
    }
}
