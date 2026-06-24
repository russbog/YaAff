<?php

require_once __DIR__ . '/Migration.php';

/**
 * Landing pages. Type (local ZIP folder / remote URL), path or URL, redirect
 * type, and protection/cloak flags live in the settings JSON bag.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS landings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_landings_name ON landings (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_landings_group ON landings (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS landings');
    }
};
