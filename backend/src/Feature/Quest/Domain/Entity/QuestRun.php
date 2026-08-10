<?php

declare(strict_types=1);

namespace App\Feature\Quest\Domain\Entity;

use App\Feature\Combat\Domain\Model\CombatLog;
use App\Feature\Combat\Domain\Model\Outcome;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use JsonException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * One character's attempt at one kill quest.
 *
 * The `snapshot` column is what makes this an expedition rather than a live
 * fight: it freezes the character's combat-relevant state at accept time
 * ({@see \App\Feature\Encounter\Application\CharacterParticipantFactory}), and
 * claiming replays exactly that state through the engine, however much later
 * the player claims. Gear changed in between can neither help nor hurt the
 * outcome — the same reproducibility guarantee Encounter already has for its
 * own snapshot, stretched over real time. See
 * docs/adr/0008-quest-snapshot-resolution.md.
 *
 * One row per (character, quest): a Failed attempt is overwritten by the next
 * accept rather than accumulating history, because nothing was spent to reach
 * Failed. See {@see QuestRunStatus}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_run')]
#[ORM\UniqueConstraint(name: 'uq_quest_run_character_quest', columns: ['character_id', 'quest_id'])]
class QuestRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $characterId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $questId;

    #[ORM\Column(type: 'string', length: 20, enumType: QuestRunStatus::class)]
    private QuestRunStatus $status;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $acceptedAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $completesAt;

    /**
     * The frozen character participant + the ruleset version it was frozen
     * under, in the same shape Encounter::snapshot() already produces.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $snapshot;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $seed = null;

    #[ORM\Column(type: 'string', length: 20, enumType: Outcome::class, nullable: true)]
    private ?Outcome $outcome = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rounds = null;

    /**
     * The combat log, gzip-compressed — same rationale as Encounter::$log:
     * large, written once, read rarely, and nothing queries inside it.
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $log = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rewards = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        Uuid $id,
        Uuid $characterId,
        string $questId,
        array $snapshot,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $completesAt,
    ) {
        $this->id = $id;
        $this->characterId = $characterId;
        $this->questId = $questId;
        $this->status = QuestRunStatus::Active;
        $this->snapshot = $snapshot;
        $this->acceptedAt = $acceptedAt;
        $this->completesAt = $completesAt;
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

    public function questId(): string
    {
        return $this->questId;
    }

    public function status(): QuestRunStatus
    {
        return $this->status;
    }

    public function acceptedAt(): DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function completesAt(): DateTimeImmutable
    {
        return $this->completesAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function isReadyToClaim(DateTimeImmutable $now): bool
    {
        return $this->status === QuestRunStatus::Active && $now >= $this->completesAt;
    }

    /**
     * Re-accepting after a failed attempt: overwrites the frozen snapshot and
     * timer in place. Refused for Active or Claimed — the caller is expected
     * to have already checked {@see status()} before calling, this is
     * defence in depth rather than the primary guard.
     *
     * @param array<string, mixed> $snapshot
     */
    public function reaccept(array $snapshot, DateTimeImmutable $acceptedAt, DateTimeImmutable $completesAt): void
    {
        if ($this->status !== QuestRunStatus::Failed) {
            throw new DomainException(sprintf(
                'Cannot re-accept quest run in status "%s".',
                $this->status->value,
            ));
        }

        $this->status = QuestRunStatus::Active;
        $this->snapshot = $snapshot;
        $this->acceptedAt = $acceptedAt;
        $this->completesAt = $completesAt;
        $this->seed = null;
        $this->outcome = null;
        $this->rounds = null;
        $this->log = null;
        $this->rewards = null;
        $this->resolvedAt = null;
    }

    /**
     * The engine refused to replay the frozen snapshot — a ruleset version
     * bump landed while this quest sat accepted, which an instant-resolving
     * encounter can never hit but a duration-gated one can. Nothing was
     * spent to reach this state, so it resolves the same way a lost fight
     * does: left re-acceptable, no rewards, no log (there was no fight).
     * See docs/adr/0008-quest-snapshot-resolution.md.
     */
    public function markContentChanged(DateTimeImmutable $now): void
    {
        $this->seed = null;
        $this->outcome = null;
        $this->rounds = null;
        $this->log = null;
        $this->rewards = null;
        $this->resolvedAt = $now;
        $this->status = QuestRunStatus::Failed;
    }

    /**
     * Records the outcome of the one simulated fight this quest resolves
     * into. Victory is terminal (Claimed); anything else leaves the quest
     * re-acceptable (Failed).
     *
     * @param array<string, mixed> $rewards Empty unless the outcome is Victory.
     */
    public function resolve(CombatLog $log, int $seed, array $rewards, DateTimeImmutable $now): void
    {
        $this->seed = $seed;
        $this->outcome = $log->outcome;
        $this->rounds = $log->rounds;
        $this->log = self::compress($log->toArray());
        $this->rewards = $rewards;
        $this->resolvedAt = $now;
        $this->status = $log->outcome === Outcome::Victory ? QuestRunStatus::Claimed : QuestRunStatus::Failed;
    }

    public function seed(): ?int
    {
        return $this->seed;
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    public function rounds(): ?int
    {
        return $this->rounds;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rewards(): ?array
    {
        return $this->rewards;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /**
     * @return array<string, mixed>|null Null when this run has never resolved.
     */
    public function log(): ?array
    {
        if ($this->log === null) {
            return null;
        }

        // Doctrine hands back a stream for blob columns after a database read,
        // but the original string when the entity is still in memory.
        $raw = is_resource($this->log) ? (string) stream_get_contents($this->log) : (string) $this->log;

        $json = gzdecode($raw);

        if ($json === false) {
            throw new RuntimeException(sprintf('Combat log for quest run %s is corrupt.', $this->id->toRfc4122()));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Combat log for quest run %s is not valid JSON.', $this->id->toRfc4122()),
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
