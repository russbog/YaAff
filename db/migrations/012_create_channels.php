<?php

require_once __DIR__ . '/Migration.php';

/**
 * Notification channels (Phase 9). A channel is a generic, data-driven alert
 * target (Telegram/webhook/email); transport and templates live in the
 * settings JSON, following the first-class entity convention from Phase 1.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_channels_name ON channels (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_channels_group ON channels (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS channels');
    }
};
