<?php

require_once __DIR__ . '/Migration.php';

/**
 * Single-column `time` indexes on the two largest tables so that retention
 * pruning (RetentionManager) can delete by time range without a full table
 * scan. Without them each batch held the SQLite write lock for seconds on a
 * multi-million-row `clicks` table and concurrent click inserts failed with
 * "database is locked".
 */
return new class implements Migration {
    public function up(DbDriver $driver): void
    {
        if ($driver->tableColumns('clicks') !== []) {
            $driver->exec('CREATE INDEX IF NOT EXISTS idx_clicks_time ON clicks (time)');
        }
        if ($driver->tableColumns('click_steps') !== []) {
            $driver->exec('CREATE INDEX IF NOT EXISTS idx_click_steps_time ON click_steps (time)');
        }
    }

    public function down(DbDriver $driver): void
    {
        $driver->exec('DROP INDEX IF EXISTS idx_clicks_time');
        $driver->exec('DROP INDEX IF EXISTS idx_click_steps_time');
    }
};
