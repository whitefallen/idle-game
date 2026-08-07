<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Freezes the inputs behind a character's daily vendor stock.
 *
 * The offers stay derived — this table stores only the two values the roll
 * consumes that a player can change during the day (their reference item level
 * and their Luck). Without it, unequipping a weapon or spending an attribute
 * point re-rolled the day's stock on the next read, which is a free reroll.
 * See docs/vendor.md section 2.
 *
 * No backfill: a character with no row for today simply has not opened the
 * Vendor yet, and the first read writes one.
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vendor_stock so a day\'s vendor offers cannot be re-rolled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_stock (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                date_key VARCHAR(10) NOT NULL,
                reference_item_level INT NOT NULL,
                luck INT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // One snapshot per character per day. This index is the mechanism, not
        // a backstop: the freeze is an INSERT … ON CONFLICT DO NOTHING against
        // it, which is how two concurrent first views of the day settle on one
        // roll instead of racing to write two.
        $this->addSql('CREATE UNIQUE INDEX uq_vendor_stock_character_day ON vendor_stock (character_id, date_key)');

        // A snapshot is worthless the day after it was taken, so this is a
        // growth table with a retention policy (RetentionPolicy::all). The
        // prune deletes by created_at, and an unindexed cutoff column turns
        // that job into a full scan.
        $this->addSql('CREATE INDEX idx_vendor_stock_created_at ON vendor_stock (created_at)');

        $this->addSql(
            'ALTER TABLE vendor_stock ADD CONSTRAINT fk_vendor_stock_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor_stock DROP CONSTRAINT fk_vendor_stock_character_id');
        $this->addSql('DROP TABLE vendor_stock');
    }
}
