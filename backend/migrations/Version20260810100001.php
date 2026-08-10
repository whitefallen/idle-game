<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds dungeon_run: one row per attempt.
 *
 * Unlike quest_run, a dungeon resolves live in one request — there is no
 * pending/snapshot state to hold between requests, so a row is written once,
 * complete, when the run ends. `stages` carries one entry per stage actually
 * fought (a run stops at the first non-Victory stage, so later stages are
 * simply absent rather than recorded as skipped); `logs` is the
 * gzip-compressed, index-aligned combat log per stage, following the same
 * rationale as encounter.log — large, written once, read rarely, and nothing
 * queries inside it. See docs/adr/0008-quest-snapshot-resolution.md.
 *
 * No backfill: dungeons did not exist before this migration.
 */
final class Version20260810100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dungeon_run for the multi-encounter dungeon feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE dungeon_run (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                dungeon_id VARCHAR(120) NOT NULL,
                stages JSON NOT NULL,
                logs BYTEA NOT NULL,
                cleared BOOLEAN NOT NULL,
                rewards JSON NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // Dungeon runs are occasional (weekly-cadence content, gated by a key),
        // so this indexes the history listing rather than any uniqueness rule.
        $this->addSql('CREATE INDEX idx_dungeon_run_character_id ON dungeon_run (character_id)');

        $this->addSql(
            'ALTER TABLE dungeon_run ADD CONSTRAINT fk_dungeon_run_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dungeon_run DROP CONSTRAINT fk_dungeon_run_character_id');
        $this->addSql('DROP TABLE dungeon_run');
    }
}
