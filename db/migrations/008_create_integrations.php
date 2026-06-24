<?php

require_once __DIR__ . '/Migration.php';

/**
 * Conversion-API integrations. Like every Phase 1 entity, all attributes (type,
 * HTTP method/URL/headers/body template, status filter, credentials) live in
 * the settings JSON bag so any platform can be modelled as data.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS integrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_integrations_name ON integrations (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_integrations_group ON integrations (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS integrations');
    }
};
