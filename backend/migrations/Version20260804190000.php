<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The idle layer: the Holding and the material stash it produces into.
 *
 * Two tables, created together because neither is useful alone — a Holding with
 * nowhere to deposit its output would advance its anchors and produce nothing.
 *
 * No backfill. A character without a `holding` row simply has not opened one
 * yet; the row is created on the first claim or assignment. Backfilling would
 * mean writing a row per existing character, all anchored at the migration
 * time, in exchange for nothing a lazily created row does not already give.
 */
final class Version20260804190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add holding and character_material for the idle layer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE character_material (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                material_id VARCHAR(120) NOT NULL,
                quantity BIGINT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // One stack per material per character. This is the guarantee, not the
        // application check: two concurrent first-time grants would otherwise
        // each insert a row and half the balance would be invisible to every
        // later read.
        $this->addSql('CREATE UNIQUE INDEX uq_character_material ON character_material (character_id, material_id)');

        // A material balance going negative is a class of bug that must be
        // impossible at the storage layer rather than merely unlikely at the
        // application layer — the same rule the currency columns follow.
        // See docs/data-model.md section 2.
        $this->addSql('ALTER TABLE character_material ADD CONSTRAINT ck_character_material_quantity CHECK (quantity >= 0)');

        $this->addSql(
            'ALTER TABLE character_material ADD CONSTRAINT fk_character_material_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE holding (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                slots JSONB NOT NULL,
                last_claimed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // One Holding per character. Lazy creation happens under the character
        // row lock, so this index is the backstop rather than the mechanism —
        // but a second waystation producing in parallel is severe enough that
        // hoping is not good enough.
        $this->addSql('CREATE UNIQUE INDEX uq_holding_character ON holding (character_id)');

        $this->addSql(
            'ALTER TABLE holding ADD CONSTRAINT fk_holding_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE holding DROP CONSTRAINT fk_holding_character_id');
        $this->addSql('DROP TABLE holding');
        $this->addSql('ALTER TABLE character_material DROP CONSTRAINT fk_character_material_character_id');
        $this->addSql('DROP TABLE character_material');
    }
}
