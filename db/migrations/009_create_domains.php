<?php

require_once __DIR__ . '/Migration.php';

/**
 * Domain pool. Like every Phase 1 entity the hostname lives in the core name
 * column and all DNS / Cloudflare / alias attributes live in the settings JSON
 * bag, so a domain is fully data-driven (no hardcoding).
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_domains_name ON domains (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_domains_group ON domains (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS domains');
    }
};
