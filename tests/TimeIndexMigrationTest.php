<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../db/Migrator.php';

final class TimeIndexMigrationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/yaaff_idx_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) {
            @unlink($f);
        }
    }

    private function indexNames(SqliteDriver $driver): array
    {
        $rows = $driver->select("SELECT name FROM sqlite_master WHERE type = 'index' AND name IN ('idx_clicks_time', 'idx_click_steps_time')");
        $names = array_map(static fn(array $r) => $r['name'], $rows);
        sort($names);
        return $names;
    }

    public function testMigrationAddsTimeIndexesToExistingTables(): void
    {
        $driver = new SqliteDriver($this->path);
        $driver->exec('CREATE TABLE clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, time INTEGER)');
        $driver->exec('CREATE TABLE click_steps (id INTEGER PRIMARY KEY AUTOINCREMENT, time INTEGER)');

        (new Migrator($driver))->migrate();

        $this->assertSame(['idx_click_steps_time', 'idx_clicks_time'], $this->indexNames($driver));
    }

    public function testMigrationSkipsMissingTables(): void
    {
        $driver = new SqliteDriver($this->path);

        (new Migrator($driver))->migrate();

        $this->assertSame([], $this->indexNames($driver));
    }

    public function testFreshSchemaHasTimeIndexes(): void
    {
        $driver = new SqliteDriver($this->path);
        $driver->exec(file_get_contents(__DIR__ . '/../db/db.sql'));

        $this->assertSame(['idx_click_steps_time', 'idx_clicks_time'], $this->indexNames($driver));
    }
}
