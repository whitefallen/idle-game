<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use JsonException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt at a dungeon: its stages, in order, stopping at the first
 * non-Victory one.
 *
 * One row per attempt, unlike QuestRun's reused row: dungeon runs are
 * occasional (weekly-cadence content, gated by a key), not something worth
 * collapsing into a single reused row the way Quest's duration-gated one-time
 * attempt is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dungeon_run')]
#[ORM\Index(name: 'idx_dungeon_run_character_id', columns: ['character_id'])]
class DungeonRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $dungeonId;

    /**
     * Per-stage outcome, in order: encounter id, seed, outcome, rounds and
     * that stage's own experience/gold. Stages after the first non-Victory
     * one are absent — the run stopped there.
     *
     * @var list<array{encounterId: string, seed: string, outcome: string, rounds: int, experience: int, gold: int}>
     */
    #[ORM\Column(type: 'json')]
    private array $stages;

    /**
     * The per-stage combat logs, gzip-compressed, index-aligned with `stages`
     * — same rationale as Encounter::$log: large, written once, read rarely.
     */
    #[ORM\Column(type: 'blob')]
    private mixed $logs;

    #[ORM\Column(type: 'boolean')]
    private bool $cleared;

    /**
     * The completion bonus and any drop-table roll, granted only when
     * `cleared` is true. Empty otherwise.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $rewards;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * The discipline pick offered on this clear — `min(3, remaining pool)`,
     * per docs/dungeons.md section 3. Null for a repeatable-dungeon run or a
     * run that didn't fully clear; an empty array is a valid, distinct state
     * (fully cleared, but this character's pool was already empty).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $offeredDisciplineIds;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $pickedDisciplineId = null;

    /**
     * @param list<array{encounterId: string, seed: string, outcome: string, rounds: int, experience: int, gold: int}> $stages
     * @param list<array<string, mixed>>                                                                              $logs
     * @param array<string, mixed>                                                                                    $rewards
     * @param list<string>|null                                                                                       $offeredDisciplineIds
     */
    public function __construct(
        Uuid $id,
        Uuid $characterId,
        string $dungeonId,
        array $stages,
        array $logs,
        bool $cleared,
        array $rewards,
        DateTimeImmutable $createdAt,
        ?array $offeredDisciplineIds = null,
    ) {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->dungeonId = $dungeonId;
        $this->stages = $stages;
        $this->cleared = $cleared;
        $this->rewards = $rewards;
        $this->createdAt = $createdAt;
        $this->offeredDisciplineIds = $offeredDisciplineIds;

        $this->logs = self::compress($logs);
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

    public function dungeonId(): string
    {
        return $this->dungeonId;
    }

    /**
     * @return list<array{encounterId: string, seed: string, outcome: string, rounds: int, experience: int, gold: int}>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    public function cleared(): bool
    {
        return $this->cleared;
    }

    /**
     * @return array<string, mixed>
     */
    public function rewards(): array
    {
        return $this->rewards;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return list<string>|null
     */
    public function offeredDisciplineIds(): ?array
    {
        return $this->offeredDisciplineIds;
    }

    public function pickedDisciplineId(): ?string
    {
        return $this->pickedDisciplineId;
    }

    /**
     * Resolves the pending offer. Refuses anything that was not actually
     * offered, and refuses a second pick — the offer is spent the moment it
     * is confirmed, same "no re-litigating a settled choice" spirit as
     * QuestRun's terminal states.
     */
    public function pickDiscipline(string $disciplineId): void
    {
        if ($this->pickedDisciplineId !== null) {
            throw new DomainException('This dungeon run\'s discipline pick has already been made.');
        }

        if ($this->offeredDisciplineIds === null || !in_array($disciplineId, $this->offeredDisciplineIds, true)) {
            throw new DomainException(sprintf('"%s" was not offered on this dungeon run.', $disciplineId));
        }

        $this->pickedDisciplineId = $disciplineId;
    }

    /**
     * @return list<array<string, mixed>> Index-aligned with {@see stages()}.
     */
    public function logs(): array
    {
        $raw = is_resource($this->logs) ? (string) stream_get_contents($this->logs) : (string) $this->logs;

        $json = gzdecode($raw);

        if ($json === false) {
            throw new RuntimeException(sprintf('Combat logs for dungeon run %s are corrupt.', $this->id->toRfc4122()));
        }

        try {
            /** @var list<array<string, mixed>> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Combat logs for dungeon run %s are not valid JSON.', $this->id->toRfc4122()),
                previous: $e,
            );
        }

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $logs
     */
    private static function compress(array $logs): string
    {
        $compressed = gzencode(json_encode($logs, JSON_THROW_ON_ERROR), 6);

        if ($compressed === false) {
            throw new RuntimeException('Failed to compress dungeon run logs.');
        }

        return $compressed;
    }
}
