<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds character_discipline: the stored half of discipline ownership.
 *
 * Level-milestone ownership stays derived, never stored — DisciplineRepository
 * computes it from the level on every read. This table is exactly the "stored
 * one" its own docblock already anticipated: existence of a row *is*
 * ownership for a quest, dungeon or reputation sourced discipline, which have
 * no level to derive from. See docs/dungeons.md section 3 for the first real
 * source to use it — the dungeon discipline-pool pick.
 *
 * No backfill: nothing has ever granted a non-level discipline before this.
 */
final class Version20260810170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add character_discipline for non-level-milestone discipline ownership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE character_discipline (
                id UUID NOT NULL,
                character_id UUID NOT NULL,
                discipline_id VARCHAR(120) NOT NULL,
                granted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // One grant per (character, discipline) — the backstop against a
        // concurrent double-grant, same role uq_character_material plays for
        // materials.
        $this->addSql('CREATE UNIQUE INDEX uq_character_discipline ON character_discipline (character_id, discipline_id)');

        // Doctrine's own FK-support index, kept exactly as it names it: a
        // hand-picked name here would just be immediately re-proposed by the
        // next migrations:diff, since this one isn't declared by any
        // #[ORM\Index] attribute for schema-comparison to match against.
        $this->addSql('CREATE INDEX IDX_C43257B91136BE75 ON character_discipline (character_id)');

        $this->addSql(
            'ALTER TABLE character_discipline ADD CONSTRAINT fk_character_discipline_character_id '
            . 'FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_discipline DROP CONSTRAINT fk_character_discipline_character_id');
        $this->addSql('DROP TABLE character_discipline');
    }
}
