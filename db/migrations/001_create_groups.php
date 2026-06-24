<?php

require_once __DIR__ . '/Migration.php';

/**
 * Grouping/folders for first-class entities. The entity type a group belongs
 * to (network, source, offer, landing, campaign, ...) lives in the schemaless
 * settings bag under "entity_type", so a single table serves every entity.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_groups_name ON groups (name)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS groups');
    }
};
