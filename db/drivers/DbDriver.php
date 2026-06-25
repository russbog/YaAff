<?php

/**
 * Database driver abstraction.
 *
 * Every database backend (SQLite now, MySQL/MariaDB later) implements this
 * interface. Application code (Db, EntityRepository, Migrator) talks only to
 * this interface and never to a concrete database extension, so adding a new
 * backend is an isolated task: write one driver class, change nothing else.
 *
 * Parameter binding accepts three shapes, auto-detected by the driver:
 *   1. Positional pairs (list):   [[value, type], [value, type], ...]
 *      bound to "?" placeholders in order.
 *   2. Named typed map (assoc):   [':name' => [value, type], ...]
 *      bound to ":name" placeholders by name.
 *   3. Legacy value-keyed map:    [value => type, ...]
 *      bound to placeholders positionally in insertion order.
 */
interface DbDriver
{
    /** Bind types (values intentionally match SQLite3 type constants). */
    public const INT = 1;
    public const FLOAT = 2;
    public const TEXT = 3;
    public const BLOB = 4;
    public const NULL = 5;

    /** Run a SELECT and return every row as an associative array. */
    public function select(string $sql, array $params = []): array;

    /** Run a SELECT and return the first row (or [] when there is none). */
    public function selectOne(string $sql, array $params = []): array;

    /** Run an INSERT/UPDATE/DELETE/DDL statement; returns success. */
    public function execute(string $sql, array $params = []): bool;

    /** Run an INSERT and return the new row id (0 on failure). */
    public function insert(string $sql, array $params = []): int;

    /** Rows affected by the most recent execute()/insert(). */
    public function affectedRows(): int;

    /** Last auto-increment id generated on the write connection. */
    public function lastInsertId(): int;

    /** Execute raw SQL (may contain several statements, e.g. schema files). */
    public function exec(string $sql): bool;

    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;

    /** Column names of a table (empty array when the table is absent). */
    public function tableColumns(string $table): array;

    /** All user table names in the database (excludes engine internals). */
    public function tables(): array;

    // --- SQL dialect helpers -------------------------------------------------

    /** SQL expression extracting a JSON value as text from $column. */
    public function jsonExtract(string $column, string $key): string;

    /** SQL expression extracting a JSON value as a real/float number. */
    public function jsonExtractReal(string $column, string $key): string;

    /**
     * SQL expression formatting an integer unix-epoch $column as YYYY-MM-DD
     * shifted by $tzOffset (e.g. "+03:00").
     */
    public function dateGroup(string $column, string $tzOffset): string;

    /**
     * SQL expression bucketing an integer unix-epoch $column at the given
     * $granularity ('hour'|'day'|'week'|'month'), shifted by $tzOffset.
     * Returns a sortable string label per bucket.
     */
    public function dateBucket(string $column, string $tzOffset, string $granularity): string;

    /** "INSERT OR IGNORE INTO" (SQLite) / "INSERT IGNORE INTO" (MySQL). */
    public function insertIgnoreInto(): string;

    /** Case-insensitive collation suffix for ORDER BY ("COLLATE NOCASE"). */
    public function caseInsensitiveCollation(): string;

    /** Scalar "largest of two values" expression (MAX/GREATEST). */
    public function greatest(string $a, string $b): string;

    /** Short backend identifier, e.g. "sqlite". */
    public function name(): string;
}
