<?php

require_once __DIR__ . '/Migration.php';

/**
 * Admin users (Phase 10). The login name is the entity `name`; the password
 * hash, role, enabled flag and API token live in the settings JSON. No users
 * are seeded — the tracker keeps its legacy single shared-password login until
 * the first account is created from the admin.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name ON users (name)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS users');
    }
};
