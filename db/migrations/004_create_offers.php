<?php

require_once __DIR__ . '/Migration.php';

/**
 * Offers. Payout, currency, caps/limits, geo, redirect type, target URL (with
 * tokens), network linkage and multi-offer value pairs are all stored in the
 * settings JSON bag so offers stay generic and reusable across campaigns.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS offers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER DEFAULT NULL,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_offers_name ON offers (name)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_offers_group ON offers (group_id)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS offers');
    }
};
