<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the pending discipline-offer columns to dungeon_run.
 *
 * Both null for a repeatable-dungeon run or one that didn't fully clear.
 * `offered_discipline_ids` is set once, at clear time (EnterDungeonHandler);
 * `picked_discipline_id` is set later, by a separate request
 * (PickDungeonDisciplineHandler) — the two-step "offer, then confirm" flow
 * docs/dungeons.md section 3 requires an explicit pick for, even when only
 * one option remains. See ADR-0008's sibling design note.
 */
final class Version20260810170001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dungeon_run.offered_discipline_ids and picked_discipline_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dungeon_run ADD offered_discipline_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE dungeon_run ADD picked_discipline_id VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dungeon_run DROP offered_discipline_ids');
        $this->addSql('ALTER TABLE dungeon_run DROP picked_discipline_id');
    }
}
