<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';

/**
 * Data retention / pruning (Phase 12).
 *
 * Deletes rows older than a configurable number of days from high-volume,
 * append-only tables (click logs, audit logs). Driver-agnostic: the same code
 * runs against SQLite and MySQL via the {@see DbDriver} abstraction. Tables or
 * columns that do not exist in a given install are silently skipped, so the
 * pruner stays correct as the schema evolves.
 */
class RetentionManager
{
    /** table => unix-epoch timestamp column used to decide row age. */
    public const TABLES = [
        'clicks' => 'time',
        'blocked' => 'time',
        'trafficback' => 'time',
        'click_event_log' => 'time',
        'click_steps' => 'time',
        'postback_log' => 'time',
        'rule_log' => 'ran_at',
        'notification_log' => 'created_at',
    ];

    private DbDriver $driver;

    public function __construct(DbDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Delete rows older than $days. A non-positive $days disables pruning and
     * returns an empty result (no rows touched).
     *
     * @return array{cutoff:int,deleted:array<string,int>} per-table delete counts.
     */
    public function prune(int $days, ?int $now = null): array
    {
        $deleted = [];
        if ($days <= 0) {
            return ['cutoff' => 0, 'deleted' => $deleted];
        }

        $now = $now ?? time();
        $cutoff = $now - ($days * 86400);

        foreach (self::TABLES as $table => $column) {
            $columns = $this->driver->tableColumns($table);
            if ($columns === [] || !in_array($column, $columns, true)) {
                continue;
            }
            $this->driver->execute(
                "DELETE FROM $table WHERE $column < ?",
                [[$cutoff, DbDriver::INT]]
            );
            $deleted[$table] = $this->driver->affectedRows();
        }

        return ['cutoff' => $cutoff, 'deleted' => $deleted];
    }
}
