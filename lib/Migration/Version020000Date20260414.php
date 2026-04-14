<?php

declare(strict_types=1);

namespace OCA\StarRate\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the starrate_comments table.
 *
 * One row per file (file_id is UNIQUE) — last writer wins (UPSERT semantics).
 * Comments are tied to the photo, not to a specific share, so they survive
 * share deletion and are visible across all shares of the same folder.
 */
class Version020000Date20260414 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('starrate_comments')) {
            return null;
        }

        $table = $schema->createTable('starrate_comments');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->addColumn('file_id', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
        ]);
        $table->addColumn('comment', Types::TEXT, [
            'notnull' => true,
        ]);
        $table->addColumn('author_type', Types::STRING, [
            'notnull' => true,
            'length'  => 8,   // 'guest' or 'owner'
        ]);
        $table->addColumn('author_name', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('updated_at', Types::INTEGER, [
            'notnull'  => true,
            'unsigned' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['file_id'], 'starrate_comments_file_id_unique');

        return $schema;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        $output->info('StarRate: starrate_comments table created.');
    }
}
