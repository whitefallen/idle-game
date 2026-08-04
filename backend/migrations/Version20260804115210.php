<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Vigor activity gate: when a character last spent Vigor.
 *
 * Nullable with no backfill, deliberately. NULL means "has never spent Vigor",
 * which is exactly the right starting state for every existing character —
 * backfilling to the migration time would gate the entire player base for the
 * gate interval the moment this deploys, for no reason.
 *
 * Adding a nullable column with no default is a catalogue-only change in
 * Postgres, so it does not rewrite the table and does not hold a lock over the
 * row count. That matters on game_character, which grows with the player base.
 */
final class Version20260804115210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_character.vigor_spent_at for the Vigor activity gate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_character ADD vigor_spent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_character DROP vigor_spent_at');
    }
}
