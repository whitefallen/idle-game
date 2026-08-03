<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One audited event.
 *
 * Append-only: this class exposes no mutator and no repository offers an update
 * or delete path. An audit record that can be edited is not evidence.
 *
 * Retention is handled by `db:retention:prune`, which deletes past the window in
 * batches. See docs/data-model.md section 6 for why that was preferred to range
 * partitioning at this scale.
 *
 * There is deliberately no foreign key on account_id or character_id: an audit
 * record must outlive what it describes, and a cascade or SET NULL would
 * destroy the trail exactly when an investigation needs it.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_log')]
// Each index leads with the column an investigation filters on and ends with
// occurred_at, which is both the sort order and the retention boundary.
#[ORM\Index(name: 'idx_audit_log_account', columns: ['account_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_character', columns: ['character_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_action', columns: ['action', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_occurred_at', columns: ['occurred_at'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 60, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $accountId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $characterId;

    /**
     * JSONB rather than JSON: investigations query inside this, and JSON stores
     * an unparsed string that cannot be indexed.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $context;

    /**
     * Hashed, never stored raw. An address identifies a person, and an audit
     * table is long-lived; the hash still supports "how many failures from one
     * source" without retaining the source itself.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $ipHash;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        Uuid $id,
        AuditAction $action,
        ?Uuid $accountId,
        ?Uuid $characterId,
        array $context,
        ?string $ipHash,
        DateTimeImmutable $occurredAt,
    ) {
        $this->id = $id;
        $this->action = $action;
        $this->accountId = $accountId;
        $this->characterId = $characterId;
        $this->context = $context;
        $this->ipHash = $ipHash;
        $this->occurredAt = $occurredAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function accountId(): ?Uuid
    {
        return $this->accountId;
    }

    public function characterId(): ?Uuid
    {
        return $this->characterId;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
