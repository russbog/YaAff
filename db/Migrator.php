<?php

require_once __DIR__ . '/drivers/DbDriver.php';
require_once __DIR__ . '/migrations/Migration.php';

/**
 * Versioned, non-destructive schema migration runner.
 *
 * Tracks which migrations have been applied in a `schema_migrations` table so
 * upgrades can be shipped without wiping existing user data: only pending
 * versions run, each exactly once, in ascending order. Migration files are
 * discovered from a directory (default db/migrations/) by their numeric
 * `NNN_*.php` filename prefix.
 */
class Migrator
{
    private DbDriver $driver;
    private string $dir;

    public function __construct(DbDriver $driver, ?string $migrationsDir = null)
    {
        $this->driver = $driver;
        $this->dir = rtrim($migrationsDir ?? __DIR__ . '/migrations', '/');
        $this->ensureVersionTable();
    }

    private function ensureVersionTable(): void
    {
        $this->driver->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                applied_at INTEGER NOT NULL
            )'
        );
    }

    /** Highest applied migration version (0 when none applied). */
    public function currentVersion(): int
    {
        $row = $this->driver->selectOne('SELECT MAX(version) AS v FROM schema_migrations');
        return (int)($row['v'] ?? 0);
    }

    /** @return list<int> Applied versions in ascending order. */
    public function appliedVersions(): array
    {
        $rows = $this->driver->select('SELECT version FROM schema_migrations ORDER BY version ASC');
        return array_map(static fn(array $r) => (int)$r['version'], $rows);
    }

    /**
     * Discover migration files on disk.
     *
     * @return array<int,array{version:int,name:string,file:string}> keyed by version, sorted ascending.
     */
    public function discover(): array
    {
        $found = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (!preg_match('/^(\d+)_(.+)$/', $base, $m)) {
                continue; // skip Migration.php and non-conforming files
            }
            $version = (int)$m[1];
            $found[$version] = ['version' => $version, 'name' => $m[2], 'file' => $file];
        }
        ksort($found);
        return $found;
    }

    /**
     * Versions discovered on disk but not yet applied.
     *
     * @return array<int,array{version:int,name:string,file:string}>
     */
    public function pending(): array
    {
        $applied = array_flip($this->appliedVersions());
        return array_filter($this->discover(), static fn(array $mig) => !isset($applied[$mig['version']]));
    }

    /**
     * Apply all pending migrations in order.
     *
     * @return list<int> Versions applied during this run.
     */
    public function migrate(): array
    {
        $applied = [];
        foreach ($this->pending() as $mig) {
            $migration = $this->load($mig['file']);
            $this->driver->beginTransaction();
            try {
                $migration->up($this->driver);
                $this->driver->execute(
                    'INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)',
                    [[$mig['version'], DbDriver::INT], [$mig['name'], DbDriver::TEXT], [time(), DbDriver::INT]]
                );
                $this->driver->commit();
            } catch (Throwable $e) {
                $this->driver->rollback();
                throw new RuntimeException(
                    "Migration {$mig['version']}_{$mig['name']} failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
            $applied[] = $mig['version'];
        }
        return $applied;
    }

    /**
     * Revert the last $steps applied migrations (newest first).
     *
     * @return list<int> Versions reverted during this run.
     */
    public function rollback(int $steps = 1): array
    {
        $discovered = $this->discover();
        $applied = array_reverse($this->appliedVersions());
        $reverted = [];

        foreach (array_slice($applied, 0, max(0, $steps)) as $version) {
            if (!isset($discovered[$version])) {
                throw new RuntimeException("Cannot roll back version $version: migration file missing");
            }
            $mig = $discovered[$version];
            $migration = $this->load($mig['file']);
            $this->driver->beginTransaction();
            try {
                $migration->down($this->driver);
                $this->driver->execute('DELETE FROM schema_migrations WHERE version = ?', [[$version, DbDriver::INT]]);
                $this->driver->commit();
            } catch (Throwable $e) {
                $this->driver->rollback();
                throw new RuntimeException(
                    "Rollback of {$mig['version']}_{$mig['name']} failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
            $reverted[] = $version;
        }
        return $reverted;
    }

    private function load(string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration file must return a Migration instance: $file");
        }
        return $migration;
    }
}
