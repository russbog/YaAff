# Phase 0: Database Abstraction, Entity Layer & Migrations

Phase 0 is the foundation every later feature builds on. It introduces three
things without changing any runtime behaviour:

1. A **database driver abstraction** so the tracker is no longer hardwired to
   SQLite (MySQL/MariaDB can be added later as a single isolated class).
2. A **generic entity repository** that provides CRUD for new first-class
   entities (sources, offers, networks, landings, …) with no per-entity
   boilerplate.
3. A **versioned migration system** so new tables can be shipped to existing
   installations without wiping user data.

SQLite remains the default. Nothing about request handling, statistics, or
postbacks changes.

## 1. Database Driver Abstraction

All database access now goes through the `DbDriver` interface
(`db/drivers/DbDriver.php`). The application code (`Db`, `EntityRepository`,
`Migrator`) never touches the `SQLite3` extension directly. The default
implementation is `SqliteDriver` (`db/drivers/SqliteDriver.php`), which keeps
the original two-handle (read-only + read-write) strategy and the WAL/PRAGMA
tuning, so performance is unchanged.

Adding another backend later is an isolated task: write one class implementing
`DbDriver` and change nothing else.

### Core methods

| Method | Purpose |
| --- | --- |
| `select($sql, $params)` | Run a SELECT, return all rows. |
| `selectOne($sql, $params)` | Run a SELECT, return the first row (or `[]`). |
| `execute($sql, $params)` | Run INSERT/UPDATE/DELETE/DDL. |
| `insert($sql, $params)` | Run an INSERT, return the new id. |
| `affectedRows()` | Rows changed by the last write. |
| `lastInsertId()` | Last auto-increment id. |
| `exec($sql)` | Run raw SQL (e.g. a schema file). |
| `beginTransaction()` / `commit()` / `rollback()` | Transactions. |
| `tableColumns($table)` | Column names of a table (or `[]`). |

### Dialect helpers

SQL that differs between databases is produced by helper methods instead of
being hardcoded, so each backend renders its own dialect:

| Helper | SQLite output |
| --- | --- |
| `jsonExtract($col, $key)` | `json_extract(col, '$.key')` |
| `jsonExtractReal($col, $key)` | `CAST(json_extract(...) AS REAL)` |
| `dateGroup($col, $tz)` | `strftime('%Y-%m-%d', datetime(col, 'unixepoch', tz))` |
| `insertIgnoreInto()` | `INSERT OR IGNORE INTO` |
| `caseInsensitiveCollation()` | `COLLATE NOCASE` |
| `greatest($a, $b)` | `MAX(a, b)` |

### Parameter binding

Bind parameters use type constants on `DbDriver` (`INT`, `FLOAT`, `TEXT`,
`BLOB`, `NULL`). Three shapes are accepted and auto-detected:

```php
// 1. Positional pairs -> "?" placeholders in order
$driver->select('SELECT * FROM t WHERE a = ? AND b = ?', [
    [$a, DbDriver::INT],
    [$b, DbDriver::TEXT],
]);

// 2. Named typed map -> ":name" placeholders
$driver->select('SELECT * FROM t WHERE a = :a', [
    ':a' => [$a, DbDriver::INT],
]);

// 3. Legacy value-keyed map (kept for backward compatibility)
$driver->select('SELECT * FROM t WHERE a = ?', [$a => DbDriver::INT]);
```

## 2. Entity Layer

New entities follow a single table convention so one repository can manage all
of them:

```
id          INTEGER PRIMARY KEY AUTOINCREMENT
name        TEXT NOT NULL
group_id    INTEGER NULL          -- optional grouping/folder
settings    TEXT NOT NULL '{}'    -- schemaless JSON configuration bag
created_at  INTEGER NOT NULL      -- unix epoch
updated_at  INTEGER NOT NULL      -- unix epoch
```

All entity-specific configuration lives inside the schemaless `settings` JSON
bag. This keeps every entity **generic**: adding a new configurable field
never requires a schema change and is never hardcoded to a specific
offer/network/source.

- `entities/Entity.php` — base class with `id`, `name`, `group_id`,
  `settings` (decoded array), `created_at`, `updated_at`, plus `get()/set()`
  helpers for the settings bag.
- `entities/EntityRepository.php` — generic CRUD: `find`, `findByName`,
  `findAll`, `count`, `save` (insert or update), `delete`.

The repository validates table and column identifiers and binds all values,
so it is safe against SQL injection. `created_at` is set once on insert and
never overwritten on update; `updated_at` is refreshed on every save.

## 3. Migration System

`db/Migrator.php` applies versioned, reversible schema changes and records
them in a `schema_migrations` table, so upgrades ship without wiping data:
only pending versions run, each exactly once, in ascending order.

Migration files live in `db/migrations/` and are named
`NNN_short_description.php`. Each file **returns** a `Migration` instance:

```php
<?php
// db/migrations/001_create_networks.php
return new class implements Migration {
    public function up(DbDriver $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS networks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
    }
    public function down(DbDriver $db): void {
        $db->exec("DROP TABLE IF EXISTS networks");
    }
};
```

Run migrations from the CLI:

```bash
php db/migrate.php           # apply all pending migrations
php db/migrate.php status    # show applied / pending versions
php db/migrate.php rollback  # revert the most recent migration
```

> Note: Phase 0 ships only the migration **framework** — there are no entity
> migrations yet. Phase 1 adds the first ones (networks, offers, sources, …).

## Example: add a new entity (Network)

1. Create a migration `db/migrations/001_create_networks.php` (see above) and
   run `php db/migrate.php`.
2. Use the generic repository — no new class is strictly required:

```php
$repo = new EntityRepository($db->driver(), 'networks');

$network = new Entity();
$network->name = 'My CPA Network';
$network->settings = [
    'postback_url' => 'https://net.example/pb?cid={clickid}&payout={payout}',
    'status_map'   => ['1' => 'sale', '2' => 'lead', '3' => 'rejected'],
    'currency'     => 'USD',
];
$repo->save($network);              // INSERT, assigns id + timestamps

$found = $repo->findByName('My CPA Network');
$found->set('currency', 'EUR');
$repo->save($found);               // UPDATE, refreshes updated_at

$all = $repo->findAll([], 'name'); // list, ordered by name
$repo->delete($found->id);
```

For richer behaviour, subclass `Entity` (e.g. `class Network extends Entity`)
and pass the subclass to the repository:
`new EntityRepository($driver, 'networks', Network::class)`.

## Backward compatibility & testing

- `Db`'s public API is unchanged; existing call sites keep working.
- The full request, statistics, and postback paths were verified end-to-end on
  a real SQLite database.
- New PHPUnit coverage: `tests/SqliteDriverTest.php`,
  `tests/EntityRepositoryTest.php`, `tests/MigratorTest.php`.
