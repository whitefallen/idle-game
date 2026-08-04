<?php

declare(strict_types=1);

namespace App\Platform\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Declares foreign keys between aggregates that reference each other by id.
 *
 * Aggregates hold each other's identifiers rather than object references, which
 * keeps features decoupled and avoids accidental lazy-loading across
 * boundaries. Doctrine therefore does not know those columns are references and
 * generates no constraint for them.
 *
 * Referential integrity still belongs in the database, not in application hope
 * (docs/data-model.md section 1), so the constraints are added to the generated
 * schema here. Doing it at schema-generation time rather than by hand-editing a
 * migration matters: Doctrine introspects foreign keys, so a constraint it does
 * not know about would be proposed for removal by the next
 * `doctrine:migrations:diff`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class CrossAggregateForeignKeyListener
{
    /**
     * table => [column, referenced table, referenced column, on delete]
     *
     * @var list<array{string, string, string, string, string}>
     */
    private const array REFERENCES = [
        ['game_character', 'account_id', 'account', 'id', 'CASCADE'],
        ['encounter', 'character_id', 'game_character', 'id', 'CASCADE'],
        ['item_instance', 'character_id', 'game_character', 'id', 'CASCADE'],
        ['character_material', 'character_id', 'game_character', 'id', 'CASCADE'],
        ['holding', 'character_id', 'game_character', 'id', 'CASCADE'],

        // audit_log deliberately has no foreign key, which is the one exception
        // to the rule in docs/data-model.md section 1. An audit record must
        // outlive what it describes: a cascade would erase the trail for a
        // deleted account, and SET NULL would erase which account it concerned
        // — in both cases destroying the evidence exactly when an
        // investigation is most likely to need it.
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        foreach (self::REFERENCES as [$table, $column, $target, $targetColumn, $onDelete]) {
            if (!$schema->hasTable($table) || !$schema->hasTable($target)) {
                continue;
            }

            $schema->getTable($table)->addForeignKeyConstraint(
                $target,
                [$column],
                [$targetColumn],
                ['onDelete' => $onDelete],
                sprintf('fk_%s_%s', $table, $column),
            );
        }
    }
}
