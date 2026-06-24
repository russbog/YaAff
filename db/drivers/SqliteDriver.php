<?php

require_once __DIR__ . '/DbDriver.php';

/**
 * SQLite implementation of {@see DbDriver}.
 *
 * Keeps the original two-handle strategy (a read-only and a read-write
 * connection) and the WAL/PRAGMA tuning that the tracker relied on, so runtime
 * behaviour and performance characteristics are unchanged after the refactor.
 */
class SqliteDriver implements DbDriver
{
    private string $dbPath;
    private ?SQLite3 $readDb = null;
    private ?SQLite3 $writeDb = null;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
    }

    public function __destruct()
    {
        if ($this->readDb !== null) {
            $this->readDb->close();
            $this->readDb = null;
        }
        if ($this->writeDb !== null) {
            $this->writeDb->close();
            $this->writeDb = null;
        }
    }

    public function name(): string
    {
        return 'sqlite';
    }

    private function connection(bool $readOnly): SQLite3
    {
        if ($readOnly && $this->readDb !== null) {
            return $this->readDb;
        }
        if (!$readOnly && $this->writeDb !== null) {
            return $this->writeDb;
        }

        $db = new SQLite3(
            $this->dbPath,
            $readOnly ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE)
        );
        $db->busyTimeout(5000);

        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = wal');
        $db->exec('PRAGMA mmap_size = 268435456');   // 256MB memory mapping
        $db->exec('PRAGMA cache_size = -64000');     // 64MB cache pages
        $db->exec('PRAGMA temp_store = MEMORY');     // temporary data in RAM

        if (!$readOnly) {
            $db->exec('PRAGMA synchronous = OFF');   // only for writing
            $this->writeDb = $db;
        } else {
            $this->readDb = $db;
        }

        return $db;
    }

    private function mapType(int $type): int
    {
        return match ($type) {
            DbDriver::INT => SQLITE3_INTEGER,
            DbDriver::FLOAT => SQLITE3_FLOAT,
            DbDriver::BLOB => SQLITE3_BLOB,
            DbDriver::NULL => SQLITE3_NULL,
            default => SQLITE3_TEXT,
        };
    }

    private function prepareBound(SQLite3 $db, string $sql, array $params): SQLite3Stmt
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("Error preparing query: " . $db->lastErrorMsg() . " | SQL: $sql");
        }

        $i = 0;
        foreach ($params as $key => $value) {
            $i++;
            if (is_array($value)) {
                // [value, type] pair: named when key is ":name", else positional.
                $bindValue = $value[0] ?? null;
                $bindType = $this->mapType((int)($value[1] ?? DbDriver::TEXT));
                $target = (is_string($key) && str_starts_with($key, ':')) ? $key : $i;
                $ok = $stmt->bindValue($target, $bindValue, $bindType);
            } else {
                // Legacy shape: array key is the bind value, element is the type.
                $ok = $stmt->bindValue($i, $key, $this->mapType((int)$value));
            }
            if ($ok === false) {
                throw new RuntimeException("Error binding parameter $i: " . $db->lastErrorMsg() . " | SQL: $sql");
            }
        }

        return $stmt;
    }

    public function select(string $sql, array $params = []): array
    {
        $db = $this->connection(true);
        $stmt = $this->prepareBound($db, $sql, $params);
        $result = $stmt->execute();
        if ($result === false) {
            throw new RuntimeException("Error executing query: " . $db->lastErrorMsg() . " | SQL: $sql");
        }

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function selectOne(string $sql, array $params = []): array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $params = []): bool
    {
        $db = $this->connection(false);
        $stmt = $this->prepareBound($db, $sql, $params);
        $result = $stmt->execute();
        if ($result === false) {
            throw new RuntimeException("Error executing query: " . $db->lastErrorMsg() . " | SQL: $sql");
        }
        return true;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return $this->lastInsertId();
    }

    public function affectedRows(): int
    {
        return $this->connection(false)->changes();
    }

    public function lastInsertId(): int
    {
        return (int)$this->connection(false)->lastInsertRowID();
    }

    public function exec(string $sql): bool
    {
        return $this->connection(false)->exec($sql);
    }

    public function beginTransaction(): bool
    {
        return $this->connection(false)->exec('BEGIN IMMEDIATE');
    }

    public function commit(): bool
    {
        return $this->connection(false)->exec('COMMIT');
    }

    public function rollback(): bool
    {
        return $this->writeDb?->exec('ROLLBACK') ?? false;
    }

    public function tableColumns(string $table): array
    {
        $columns = [];
        $result = $this->connection(true)->query('PRAGMA table_info(' . $table . ')');
        while ($row = $result?->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = (string)($row['name'] ?? '');
        }
        return array_values(array_filter($columns, static fn($c) => $c !== ''));
    }

    public function tables(): array
    {
        $names = [];
        $result = $this->connection(true)->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        while ($row = $result?->fetchArray(SQLITE3_ASSOC)) {
            $names[] = (string)($row['name'] ?? '');
        }
        return array_values(array_filter($names, static fn($n) => $n !== ''));
    }

    public function jsonExtract(string $column, string $key): string
    {
        return "json_extract($column, '$." . $key . "')";
    }

    public function jsonExtractReal(string $column, string $key): string
    {
        return "CAST(json_extract($column, '$." . $key . "') AS REAL)";
    }

    public function dateGroup(string $column, string $tzOffset): string
    {
        return "strftime('%Y-%m-%d', datetime($column, 'unixepoch', '$tzOffset'))";
    }

    public function insertIgnoreInto(): string
    {
        return 'INSERT OR IGNORE INTO';
    }

    public function caseInsensitiveCollation(): string
    {
        return 'COLLATE NOCASE';
    }

    public function greatest(string $a, string $b): string
    {
        return "MAX($a, $b)";
    }
}
