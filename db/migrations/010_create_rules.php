<?php

require_once __DIR__ . '/Migration.php';

/**
 * Automation rules (Phase 8). A rule is a first-class entity: its schedule,
 * metric conditions and actions all live in the settings JSON bag, so any
 * automation (pause on low ROI, weight correction, scheduled export, blacklist
 * refresh, ...) is modelled purely as data with no hardcoding.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_rules_name ON rules (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_rules_group ON rules (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS rules');
    }
};
