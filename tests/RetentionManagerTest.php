<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../data/RetentionManager.php';

class RetentionManagerTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytret') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec('CREATE TABLE clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, time INTEGER)');
        $this->driver->exec('CREATE TABLE rule_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ran_at INTEGER)');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->path . $s);
        }
    }

    private function seed(string $table, string $col, array $times): void
    {
        foreach ($times as $t) {
            $this->driver->insert("INSERT INTO $table ($col) VALUES (?)", [[$t, DbDriver::INT]]);
        }
    }

    public function testPruneDeletesOldRowsAcrossTables(): void
    {
        $now = 1_000_000_000;
        $day = 86400;
        $this->seed('clicks', 'time', [$now - 40 * $day, $now - 20 * $day, $now - 5 * $day]);
        $this->seed('rule_log', 'ran_at', [$now - 40 * $day, $now - 1 * $day]);

        $result = (new RetentionManager($this->driver))->prune(30, $now);

        $this->assertSame(1, $result['deleted']['clicks']);
        $this->assertSame(1, $result['deleted']['rule_log']);
        $this->assertSame($now - 30 * $day, $result['cutoff']);
        $this->assertCount(2, $this->driver->select('SELECT * FROM clicks'));
        $this->assertCount(1, $this->driver->select('SELECT * FROM rule_log'));
    }

    public function testPruneDisabledWithZeroDays(): void
    {
        $this->seed('clicks', 'time', [1, 2, 3]);
        $result = (new RetentionManager($this->driver))->prune(0);
        $this->assertSame([], $result['deleted']);
        $this->assertCount(3, $this->driver->select('SELECT * FROM clicks'));
    }

    public function testPruneSkipsMissingTables(): void
    {
        // notification_log isn't created in this fixture; must be skipped silently.
        $now = 1_000_000_000;
        $this->seed('clicks', 'time', [$now - 100 * 86400]);
        $result = (new RetentionManager($this->driver))->prune(10, $now);
        $this->assertArrayHasKey('clicks', $result['deleted']);
        $this->assertArrayNotHasKey('notification_log', $result['deleted']);
    }
}
