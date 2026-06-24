<?php

require_once __DIR__ . '/Migration.php';

/**
 * Audit trail for notification deliveries (Phase 9). Every send attempt — Telegram,
 * webhook or email — records its outcome, mirroring the postback_log (Phase 3)
 * and rule_log (Phase 8) audit pattern. Secrets (e.g. bot tokens) are never
 * stored: `target` holds a sanitized destination only.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS notification_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                type TEXT NOT NULL DEFAULT '',
                event TEXT NOT NULL DEFAULT '',
                ok INTEGER NOT NULL DEFAULT 0,
                target TEXT NOT NULL DEFAULT '',
                message TEXT NOT NULL DEFAULT '',
                code INTEGER NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_notification_log_channel ON notification_log (channel_id)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_notification_log_created ON notification_log (created_at)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS notification_log');
    }
};
