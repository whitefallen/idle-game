<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit log.
 *
 * An ordinary table, not partitioned. Retention is handled by
 * `db:retention:prune`, which deletes in batches — see docs/data-model.md
 * section 6 for why that was chosen over range partitioning at this scale.
 *
 * Deliberately no foreign key on account_id or character_id, the single
 * exception to the rule in docs/data-model.md section 1: an audit record must
 * outlive what it describes. A cascade would erase the trail for a deleted
 * account and SET NULL would erase which account it concerned, in both cases
 * destroying the evidence exactly when an investigation would need it.
 *
 * context is JSONB rather than JSON because investigations query inside it, and
 * JSON stores an unparsed string that cannot be indexed.
 */
final class Version20260803175106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, action VARCHAR(60) NOT NULL, account_id UUID DEFAULT NULL, character_id UUID DEFAULT NULL, context JSONB NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // Each index leads with the column an investigation filters on and ends
        // with occurred_at, which is both the sort order and the boundary the
        // retention prune deletes against.
        $this->addSql('CREATE INDEX idx_audit_log_account ON audit_log (account_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_log_character ON audit_log (character_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_log_action ON audit_log (action, occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }
}
