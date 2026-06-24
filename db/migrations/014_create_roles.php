<?php

require_once __DIR__ . '/Migration.php';

/**
 * Access roles (Phase 10). A role is a named, data-driven bundle of permission
 * strings stored in the settings JSON, following the first-class entity
 * convention from Phase 1. Three starter roles are seeded; they are plain data
 * and can be edited or removed from the admin.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_roles_name ON roles (name)');

        $seed = [
            'Admin' => ['description' => 'Full access', 'permissions' => ['*']],
            'Manager' => [
                'description' => 'Manage entities and view reports',
                'permissions' => [
                    'campaigns.*', 'networks.*', 'sources.*', 'offers.*', 'landings.*',
                    'integrations.*', 'domains.*', 'rules.*', 'channels.*',
                    'conversions.*', 'reports.*',
                ],
            ],
            'Analyst' => [
                'description' => 'Read-only reporting',
                'permissions' => ['*.view', 'reports.view', 'conversions.view'],
            ],
        ];
        // Seed starter roles. Migrations run exactly once (tracked in
        // schema_migrations), so inserting unconditionally is safe and avoids
        // reading a table the write connection has just created.
        $now = time();
        foreach ($seed as $name => $settings) {
            $driver->insert(
                'INSERT INTO roles (name, settings, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [
                    [$name, DbDriver::TEXT],
                    [json_encode($settings), DbDriver::TEXT],
                    [$now, DbDriver::INT],
                    [$now, DbDriver::INT],
                ]
            );
        }
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS roles');
    }
};
