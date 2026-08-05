<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refinement's only piece of state: how many of its ten levels an item has.
 *
 * Everything else about refinement — cost, the bonus it grants — is derived
 * from this column plus the item's level, never stored. Defaulted to 0 rather
 * than nullable, because every item is unrefined until a player spends
 * something to change that; there is no third state.
 */
final class Version20260805074341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add item_instance.refine_level for the refinement sink';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_instance ADD refine_level INT NOT NULL DEFAULT 0');

        // A refine level outside 0..10 is a class of bug that must be
        // impossible at the storage layer, the same rule the currency columns
        // follow (docs/data-model.md section 2).
        $this->addSql(
            'ALTER TABLE item_instance ADD CONSTRAINT ck_item_instance_refine_level '
            . 'CHECK (refine_level >= 0 AND refine_level <= 10)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_instance DROP CONSTRAINT ck_item_instance_refine_level');
        $this->addSql('ALTER TABLE item_instance DROP refine_level');
    }
}
