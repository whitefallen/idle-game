<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260803190938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE item_instance (id UUID NOT NULL, character_id UUID NOT NULL, definition_id VARCHAR(120) NOT NULL, item_level INT NOT NULL, rarity VARCHAR(20) NOT NULL, affixes JSONB NOT NULL, equipped_slot VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_item_instance_character_id ON item_instance (character_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_item_instance_equipped ON item_instance (character_id, equipped_slot) WHERE (equipped_slot IS NOT NULL)');
        $this->addSql('ALTER TABLE item_instance ADD CONSTRAINT fk_item_instance_character_id FOREIGN KEY (character_id) REFERENCES game_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item_instance DROP CONSTRAINT fk_item_instance_character_id');
        $this->addSql('DROP TABLE item_instance');
    }
}
