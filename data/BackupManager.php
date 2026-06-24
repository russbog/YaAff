<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';

/**
 * Driver-agnostic logical backup/restore (Phase 12).
 *
 * A backup is a portable JSON archive of every table's columns and rows, so a
 * dump taken on SQLite can be restored onto MySQL/MariaDB and vice versa. The
 * schema itself is reproduced by migrations, not by the archive: restore only
 * replaces row data of tables that already exist in the target database.
 */
class BackupManager
{
    public const VERSION = 1;

    private DbDriver $driver;

    public function __construct(DbDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Build an in-memory archive of all tables.
     *
     * @param string[] $exclude tables to skip (e.g. high-volume click logs).
     * @return array{version:int,created_at:int,driver:string,tables:array<string,array{columns:string[],rows:array<int,array<string,mixed>>}>}
     */
    public function backup(array $exclude = []): array
    {
        $tables = [];
        foreach ($this->driver->tables() as $table) {
            if (in_array($table, $exclude, true)) {
                continue;
            }
            $columns = $this->driver->tableColumns($table);
            if ($columns === []) {
                continue;
            }
            $tables[$table] = [
                'columns' => $columns,
                'rows' => $this->driver->select("SELECT * FROM $table"),
            ];
        }

        return [
            'version' => self::VERSION,
            'created_at' => time(),
            'driver' => $this->driver->name(),
            'tables' => $tables,
        ];
    }

    /** Serialize a backup archive to a JSON string. */
    public function backupJson(array $exclude = []): string
    {
        return (string)json_encode(
            $this->backup($exclude),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * Restore row data from an archive, replacing the contents of every table
     * present in BOTH the archive and the live database. Runs in a single
     * transaction with foreign-key enforcement disabled so table order and
     * cross-references never block the load.
     *
     * @param array<string,mixed> $archive decoded archive (see {@see backup()}).
     * @return array<string,int> table => number of rows inserted.
     */
    public function restore(array $archive): array
    {
        $tables = $archive['tables'] ?? null;
        if (!is_array($tables)) {
            throw new InvalidArgumentException('Archive has no tables');
        }

        $existing = $this->driver->tables();
        $counts = [];

        $this->setForeignKeyChecks(false);
        $this->driver->beginTransaction();
        try {
            foreach ($tables as $table => $payload) {
                $table = (string)$table;
                if (!in_array($table, $existing, true) || !is_array($payload)) {
                    continue;
                }
                $columns = $this->driver->tableColumns($table);
                if ($columns === []) {
                    continue;
                }
                $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

                $this->driver->execute("DELETE FROM $table");
                $counts[$table] = $this->insertRows($table, $columns, $rows);
            }
            $this->driver->commit();
        } catch (Throwable $e) {
            $this->driver->rollback();
            $this->setForeignKeyChecks(true);
            throw $e;
        }
        $this->setForeignKeyChecks(true);

        return $counts;
    }

    /** Decode a JSON archive and restore it. */
    public function restoreJson(string $json): array
    {
        $archive = json_decode($json, true);
        if (!is_array($archive)) {
            throw new InvalidArgumentException('Archive is not valid JSON');
        }
        return $this->restore($archive);
    }

    /**
     * @param string[]                          $columns
     * @param array<int,array<string,mixed>>    $rows
     */
    private function insertRows(string $table, array $columns, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $colList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO $table ($colList) VALUES ($placeholders)";

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $params = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $params[] = $value === null
                    ? [null, DbDriver::NULL]
                    : [is_bool($value) ? (int)$value : $value, DbDriver::TEXT];
            }
            $this->driver->execute($sql, $params);
            $count++;
        }
        return $count;
    }

    private function setForeignKeyChecks(bool $on): void
    {
        if ($this->driver->name() === 'mysql') {
            $this->driver->exec('SET FOREIGN_KEY_CHECKS=' . ($on ? '1' : '0'));
        } else {
            $this->driver->exec('PRAGMA foreign_keys=' . ($on ? 'ON' : 'OFF'));
        }
    }
}
