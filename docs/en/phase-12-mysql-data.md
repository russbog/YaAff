# Phase 12 — MySQL/MariaDB driver + backup / restore / retention

A second database backend (MySQL/MariaDB) implemented behind the Phase 0
`DbDriver` abstraction, plus operational data tooling: portable logical
backup/restore and configurable data retention/pruning.

## Choosing the backend

`settings.php`:

```php
// "sqlite" (default) or "mysql"
"dbDriver" => "sqlite",

// used only when dbDriver = "mysql"
"mysql" => [
    "host"     => "127.0.0.1",
    "port"     => 3306,
    "database" => "yaaff",
    "username" => "yaaff",
    "password" => "",
    "socket"   => "",   // optional unix socket; overrides host/port when set
],

// data retention: delete click/log rows older than N days (0 = disabled)
"retentionDays" => 0,
```

`db/db.php` instantiates the right driver from `dbDriver`. Application code
(`Db`, `EntityRepository`, `Migrator`, reports, …) never references a concrete
driver — only the `DbDriver` interface — so the backend is a single config
switch.

## Single source of truth for the schema

The schema lives **once**, authored in SQLite dialect (`db/db.sql` +
`db/migrations/*`). Rather than maintaining a parallel MySQL schema, the MySQL
driver runs every statement through `SqlDialect`, a stateful translator:

| SQLite                         | MySQL                                  |
|--------------------------------|----------------------------------------|
| `INTEGER`                      | `BIGINT`                               |
| `NUMERIC` / `DECIMAL`          | `DECIMAL(20,6)`                         |
| `REAL` / `FLOAT`               | `DOUBLE`                               |
| `TEXT` (plain)                 | `LONGTEXT` (holds large JSON)          |
| `TEXT` (indexed/constrained)   | `VARCHAR(191)`                         |
| `AUTOINCREMENT`                | `AUTO_INCREMENT`                       |
| `INSERT OR IGNORE`             | `INSERT IGNORE`                        |
| `COLLATE NOCASE`               | (stripped; utf8mb4 default collation)  |
| `PRAGMA …` / `BEGIN`/`COMMIT`  | (stripped; driver manages txns)        |
| —                              | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`|

MySQL cannot index a `TEXT`/`BLOB` column without a prefix and refuses foreign
keys on them. `SqlDialect` solves this in two passes: it first scans the whole
batch to learn every column referenced by an index/constraint, then emits those
`TEXT` columns as `VARCHAR(191)` and the rest as `LONGTEXT`. When a table and
its index are created in **separate** statements (migrations), the column —
first emitted as `LONGTEXT` — is widened back with an `ALTER TABLE … MODIFY`
before the index is created.

```
SqliteDriver ─┐
              ├─ implements DbDriver
MysqlDriver ──┘   └─ routes all SQL through SqlDialect -> PDO
```

`MysqlDriver` is PDO-based: lazy connection, `utf8mb4`, exception error mode,
parameter binding mapped from the `DbDriver` type constants to `PDO::PARAM_*`,
supporting both positional (`?`) and named (`:name`) placeholders.

## Backup & restore

`data/BackupManager.php` produces a **portable JSON archive** of every table's
columns and rows. Because it is logical (not a binary dump), a backup taken on
SQLite restores onto MySQL and vice versa. The schema itself is reproduced by
migrations; restore only replaces the row data of tables that exist in both the
archive and the target database.

- `backup($exclude=[])` / `backupJson()` — snapshot all tables.
- `restore($archive)` / `restoreJson($json)` — replace matching tables in one
  transaction with foreign-key checks disabled (so table order never blocks the
  load). Returns per-table inserted-row counts.

UI: **admin → Data**. Download a backup, upload one to restore (with a
confirmation), and view per-table row counts and the active driver.

## Retention / pruning

`data/RetentionManager.php` deletes rows older than `retentionDays` from
high-volume, append-only tables, driver-agnostically. Tables/columns missing
from a given install are skipped, so it stays correct as the schema evolves:

| table              | age column   |
|--------------------|--------------|
| `clicks`           | `time`       |
| `blocked`          | `time`       |
| `trafficback`      | `time`       |
| `click_event_log`  | `time`       |
| `click_steps`      | `time`       |
| `postback_log`     | `time`       |
| `rule_log`         | `ran_at`     |
| `notification_log` | `created_at` |

Run from cron (daily):

```
17 4 * * * php /path/to/bin/run_retention.php >> /var/log/yatds-retention.log 2>&1
```

Ad-hoc override: `php bin/run_retention.php --days=30`. It can also be triggered
from **admin → Data → Prune**.

## Tests

- `tests/SqlDialectTest.php` — pure translator tests (types, AUTOINCREMENT,
  TEXT sizing, index ALTERs, `INSERT OR IGNORE`, PRAGMA stripping, …).
- `tests/BackupManagerTest.php` — backup/restore round-trip on SQLite.
- `tests/RetentionManagerTest.php` — pruning across tables, disabled mode,
  missing tables.
- `tests/MysqlDriverTest.php` — integration tests against a live MySQL/MariaDB
  server; **skipped automatically** when no server / `pdo_mysql` is available,
  so the suite stays green on SQLite-only machines.
