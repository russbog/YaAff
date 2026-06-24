<?php

require_once __DIR__ . '/Migration.php';

/**
 * Audit trail for automation-rule executions (Phase 8). Every scheduler run
 * that fires a rule records whether its conditions matched and what its actions
 * did, mirroring the postback_log audit pattern from Phase 3.
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        $driver->exec(
            "CREATE TABLE IF NOT EXISTS rule_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rule_id INTEGER NOT NULL,
                ran_at INTEGER NOT NULL DEFAULT 0,
                matched INTEGER NOT NULL DEFAULT 0,
                metrics TEXT NOT NULL DEFAULT '{}',
                actions TEXT NOT NULL DEFAULT '[]',
                message TEXT NOT NULL DEFAULT ''
            )"
        );
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_rule_log_rule ON rule_log (rule_id)');
        $driver->exec('CREATE INDEX IF NOT EXISTS idx_rule_log_ran ON rule_log (ran_at)');
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP TABLE IF EXISTS rule_log');
    }
};
