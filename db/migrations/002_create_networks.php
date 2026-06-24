<?php

require_once __DIR__ . '/Migration.php';

/**
 * Affiliate networks. All network-specific configuration (incoming postback
 * URL template, status mapping, default currency, offer parameter template)
 * is stored generically in the settings JSON bag.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS networks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_networks_name ON networks (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_networks_group ON networks (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS networks');
    }
};
