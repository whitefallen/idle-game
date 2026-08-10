<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds quest_run: one row per (character, quest), reused across attempts.
 *
 * A quest is an expedition, not a live fight: accepting it freezes the
 * character as a combat snapshot (`snapshot`) and starts a timer
 * (`completes_at`); claiming it, once the timer has elapsed, replays that
 * snapshot through one simulated fight. `seed`, `outcome`, `rounds`, `log`
 * and `rewards` stay null until that claim happens. See
 * docs/adr/0008-quest-snapshot-resolution.md.
 *
 * The unique index on (character_id, quest_id) is the mechanism, not a
 * backstop: a failed attempt is overwritten in place by the next accept
 * rather than accumulating a history row, because nothing was spent to reach
 * Failed. A Claimed row is terminal — the fixed, one-time reward already
 * paid out — and the application layer refuses to reuse it.
 *
 * No backfill. A character with no row for a quest simply has not accepted
 * it yet.
 */
final class Version20260810100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quest_run for the kill-quest expedition model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quest_run (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                quest_id VARCHAR(120) NOT NULL,
                status VARCHAR(20) NOT NULL,
                accepted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completes_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                snapshot JSON NOT NULL,
                seed BIGINT DEFAULT NULL,
                outcome VARCHAR(20) DEFAULT NULL,
                rounds INT DEFAULT NULL,
                log BYTEA DEFAULT NULL,
                rewards JSON DEFAULT NULL,
                resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // One attempt per character per quest, live across accept/claim/re-accept.
        // This is what AcceptQuestHandler's "reuse or refuse" logic is entitled
        // to lean on: two concurrent first-time accepts cannot both insert a row.
        $this->addSql('CREATE UNIQUE INDEX uq_quest_run_character_quest ON quest_run (character_id, quest_id)');

        $this->addSql(
            'ALTER TABLE quest_run ADD CONSTRAINT fk_quest_run_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_run DROP CONSTRAINT fk_quest_run_character_id');
        $this->addSql('DROP TABLE quest_run');
    }
}
