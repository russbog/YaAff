<?php

require_once __DIR__ . '/Migration.php';

/**
 * Conversions live in their own table (separate from clicks) so a single click
 * can carry multiple conversions, each keyed by an external transaction id
 * (tid). Dedup is enforced per campaign on a configurable key stored in
 * dedup_key. The raw column keeps the original request payload as JSON.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS conversions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER,
                clickid TEXT NOT NULL,
                tid TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL,
                payout NUMERIC NOT NULL DEFAULT 0,
                revenue NUMERIC NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT 'USD',
                time INTEGER NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT '',
                dedup_key TEXT NOT NULL DEFAULT '',
                raw TEXT NOT NULL DEFAULT '{}'
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_conv_camp_time ON conversions (campaign_id, time)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_conv_clickid ON conversions (clickid)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_conv_status ON conversions (status)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_conv_dedup ON conversions (campaign_id, dedup_key)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS conversions');
    }
};
