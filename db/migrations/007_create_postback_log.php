<?php

require_once __DIR__ . '/Migration.php';

/**
 * Audit trail for every postback the tracker receives (direction "in") and
 * every S2S/Conversion-API request it fires (direction "out"). Surfaced in the
 * admin Conversions page for debugging integrations.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS postback_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                time INTEGER NOT NULL DEFAULT 0,
                direction TEXT NOT NULL DEFAULT 'in',
                clickid TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '',
                payout NUMERIC NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT '',
                target TEXT NOT NULL DEFAULT '',
                http_code INTEGER NOT NULL DEFAULT 0,
                message TEXT NOT NULL DEFAULT ''
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_pblog_time ON postback_log (time)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_pblog_clickid ON postback_log (clickid)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_pblog_dir ON postback_log (direction)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS postback_log');
    }
};
