<?php

require_once __DIR__ . '/Migration.php';

/**
 * Traffic sources. Incoming parameter mapping, outgoing S2S postback template,
 * cost token configuration and the cost-updater hook config all live in the
 * settings JSON bag, so a source is fully data-driven (no hardcoding).
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_sources_name ON sources (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_sources_group ON sources (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS sources');
    }
};
