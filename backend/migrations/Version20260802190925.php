<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: accounts and characters.
 *
 * The CHECK constraints below are hand-written rather than generated: DBAL has
 * no first-class support for them, and it does not introspect them either, so
 * they survive future `migrations:diff` runs untouched.
 *
 * They exist because a currency going negative is a class of bug that must be
 * impossible at the storage layer, not merely unlikely at the application
 * layer. The application checks the same invariants to produce a good error
 * message; these are the actual guarantee. See docs/data-model.md section 3.
 */
final class Version20260802190925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: account and game_character';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_account_status ON account (status)');
        $this->addSql('CREATE UNIQUE INDEX uq_account_email ON account (email)');

        $this->addSql('CREATE TABLE game_character (id UUID NOT NULL, account_id UUID NOT NULL, name VARCHAR(24) NOT NULL, level INT NOT NULL, experience BIGINT NOT NULL, gold BIGINT NOT NULL, emberdust BIGINT NOT NULL, unspent_points INT NOT NULL, strength INT NOT NULL, dexterity INT NOT NULL, intelligence INT NOT NULL, constitution INT NOT NULL, luck INT NOT NULL, vigor_current INT NOT NULL, vigor_ticked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, battle_plan JSON NOT NULL, ability_ids JSON NOT NULL, power_score INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_character_account_id ON game_character (account_id)');
        $this->addSql('CREATE INDEX idx_character_power_score ON game_character (power_score)');
        $this->addSql('CREATE INDEX idx_character_level ON game_character (level)');
        $this->addSql('CREATE UNIQUE INDEX uq_character_name ON game_character (name)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT fk_game_character_account_id FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_gold_non_negative CHECK (gold >= 0)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_emberdust_non_negative CHECK (emberdust >= 0)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_experience_non_negative CHECK (experience >= 0)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_vigor_non_negative CHECK (vigor_current >= 0)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_points_non_negative CHECK (unspent_points >= 0)');
        $this->addSql('ALTER TABLE game_character ADD CONSTRAINT ck_character_level_in_range CHECK (level >= 1 AND level <= 60)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_character DROP CONSTRAINT fk_game_character_account_id');
        $this->addSql('DROP TABLE game_character');
        $this->addSql('DROP TABLE account');
    }
}
