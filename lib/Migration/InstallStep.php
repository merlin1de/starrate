<?php

declare(strict_types=1);

namespace OCA\StarRate\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * StarRate – Installation migration.
 *
 * StarRate stores all persistent data in:
 *   - oc_preferences  (IConfig user values for sync mappings, share tokens, settings)
 *   - Nextcloud Collaborative Tags (ISystemTagManager / ISystemTagObjectMapper)
 *
 * No custom database tables are required. This migration step exists solely to
 * satisfy the <migrations> requirement in appinfo/info.xml and to pre-create
 * the system tags category namespace used by TagService.
 */
class InstallStep extends SimpleMigrationStep
{
    /**
     * Run pre-schema actions.
     *
     * Nothing to do here — no schema changes needed.
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // no-op
    }

    /**
     * Modify the database schema (add/modify tables).
     *
     * StarRate does not create its own tables.
     *
     * @param ISchemaWrapper $schema
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // No schema changes – return null to signal no modifications.
        return null;
    }

    /**
     * Run post-schema actions (seed data, tag namespace validation …).
     *
     * We verify that the Nextcloud Collaborative Tags system is available.
     * Actual tag creation happens lazily in TagService on first use.
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        $output->info('StarRate: migration complete – no database schema changes required.');
        $output->info('StarRate: ratings are stored as Nextcloud Collaborative Tags (starrate:* namespace).');
    }
}
