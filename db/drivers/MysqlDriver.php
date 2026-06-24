<?php

require_once __DIR__ . '/DbDriver.php';
require_once __DIR__ . '/SqlDialect.php';

/**
 * MySQL/MariaDB implementation of {@see DbDriver} (Phase 12).
 *
 * Application SQL is authored in SQLite dialect; every statement is routed
 * through {@see SqlDialect} so the same schema and queries run unchanged on
 * MySQL. A single PDO connection is used — unlike SQLite there is no need for a
 * separate read handle since the server handles concurrency.
 *
 * Enable it in settings.php with "dbDriver" => "mysql" and a "mysql" config
 * block; SQLite remains the default.
 */
class MysqlDriver implements DbDriver
{
    /** @var array<string,mixed> */
    private array $config;
    private ?PDO $pdo = null;
    private SqlDialect $dialect;
    private int $affected = 0;

    /** @param array<string,mixed> $config host/port/database/username/password/socket */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->dialect = new SqlDialect();
    }

    public function name(): string
    {
        return 'mysql';
    }

    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $port = (int)($this->config['port'] ?? 3306);
        $database = (string)($this->config['database'] ?? '');
        $socket = (string)($this->config['socket'] ?? '');

        if ($socket !== '') {
            $dsn = "mysql:unix_socket=$socket;dbname=$database;charset=utf8mb4";
        } else {
            $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        }

        $pdo = new PDO(
            $dsn,
            (string)($this->config['username'] ?? ''),
            (string)($this->config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );
        $pdo->exec('SET NAMES utf8mb4');
        $this->pdo = $pdo;
        return $pdo;
    }

    private function mapType(int $type): int
    {
        return match ($type) {
            DbDriver::INT => PDO::PARAM_INT,
            DbDriver::NULL => PDO::PARAM_NULL,
            DbDriver::BLOB => PDO::PARAM_LOB,
            default => PDO::PARAM_STR, // FLOAT/TEXT bound as strings (decimal-safe)
        };
    }

    private function prepareBound(string $sql, array $params): PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);

        $i = 0;
        foreach ($params as $key => $value) {
            $i++;
            if (is_array($value)) {
                $bindValue = $value[0] ?? null;
                $bindType = $this->mapType((int)($value[1] ?? DbDriver::TEXT));
                $target = (is_string($key) && str_starts_with($key, ':')) ? $key : $i;
            } else {
                $bindValue = $key;
                $bindType = $this->mapType((int)$value);
                $target = $i;
            }
            if ($bindValue === null) {
                $bindType = PDO::PARAM_NULL;
            }
            $stmt->bindValue($target, $bindValue, $bindType);
        }

        return $stmt;
    }

    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->prepareBound($this->dialect->toMysql($sql), $params);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function selectOne(string $sql, array $params = []): array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->prepareBound($this->dialect->toMysql($sql), $params);
        $stmt->execute();
        $this->affected = $stmt->rowCount();
        return true;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return $this->lastInsertId();
    }

    public function affectedRows(): int
    {
        return $this->affected;
    }

    public function lastInsertId(): int
    {
        return (int)$this->connection()->lastInsertId();
    }

    public function exec(string $sql): bool
    {
        return $this->runStatements($this->dialect->toMysql($sql));
    }

    /** Run a (possibly multi-) statement script one statement at a time. */
    private function runStatements(string $sql): bool
    {
        $pdo = $this->connection();
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            if ($pdo->exec($statement) === false) {
                return false;
            }
        }
        return true;
    }

    public function beginTransaction(): bool
    {
        return $this->connection()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection()->inTransaction() ? $this->connection()->commit() : true;
    }

    public function rollback(): bool
    {
        return $this->connection()->inTransaction() ? $this->connection()->rollBack() : false;
    }

    public function tableColumns(string $table): array
    {
        try {
            $stmt = $this->connection()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            if ($stmt === false) {
                return [];
            }
            $columns = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[] = (string)($row['Field'] ?? '');
            }
            return array_values(array_filter($columns, static fn($c) => $c !== ''));
        } catch (PDOException $e) {
            return [];
        }
    }

    public function tables(): array
    {
        $stmt = $this->connection()->query('SHOW TABLES');
        if ($stmt === false) {
            return [];
        }
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $names[] = (string)($row[0] ?? '');
        }
        sort($names);
        return array_values(array_filter($names, static fn($n) => $n !== ''));
    }

    public function jsonExtract(string $column, string $key): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT($column, '$." . $key . "'))";
    }

    public function jsonExtractReal(string $column, string $key): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT($column, '$." . $key . "')) AS DECIMAL(30,6))";
    }

    public function dateGroup(string $column, string $tzOffset): string
    {
        return "DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME($column), '+00:00', '$tzOffset'), '%Y-%m-%d')";
    }

    public function insertIgnoreInto(): string
    {
        return 'INSERT IGNORE INTO';
    }

    public function caseInsensitiveCollation(): string
    {
        return '';
    }

    public function greatest(string $a, string $b): string
    {
        return "GREATEST($a, $b)";
    }
}
