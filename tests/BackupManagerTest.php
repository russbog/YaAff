<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../data/BackupManager.php';

class BackupManagerTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytbak') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec('CREATE TABLE a (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)');
        $this->driver->exec('CREATE TABLE b (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT)');
        $this->driver->insert('INSERT INTO a (name, qty) VALUES (?, ?)', [['x', DbDriver::TEXT], [1, DbDriver::INT]]);
        $this->driver->insert('INSERT INTO a (name, qty) VALUES (?, ?)', [['y', DbDriver::TEXT], [2, DbDriver::INT]]);
        $this->driver->insert('INSERT INTO b (data) VALUES (?)', [[json_encode(['k' => 'v']), DbDriver::TEXT]]);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->path . $s);
        }
    }

    public function testBackupCapturesTablesAndRows(): void
    {
        $archive = (new BackupManager($this->driver))->backup();
        $this->assertSame(BackupManager::VERSION, $archive['version']);
        $this->assertSame('sqlite', $archive['driver']);
        $this->assertArrayHasKey('a', $archive['tables']);
        $this->assertArrayHasKey('b', $archive['tables']);
        $this->assertSame(['id', 'name', 'qty'], $archive['tables']['a']['columns']);
        $this->assertCount(2, $archive['tables']['a']['rows']);
    }

    public function testBackupExcludeSkipsTable(): void
    {
        $archive = (new BackupManager($this->driver))->backup(['b']);
        $this->assertArrayHasKey('a', $archive['tables']);
        $this->assertArrayNotHasKey('b', $archive['tables']);
    }

    public function testBackupJsonIsValid(): void
    {
        $json = (new BackupManager($this->driver))->backupJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tables', $decoded);
    }

    public function testRestoreReplacesData(): void
    {
        $manager = new BackupManager($this->driver);
        $json = $manager->backupJson();

        // Mutate the live DB after the snapshot.
        $this->driver->execute('DELETE FROM a');
        $this->driver->insert('INSERT INTO a (name, qty) VALUES (?, ?)', [['z', DbDriver::TEXT], [9, DbDriver::INT]]);
        $this->assertCount(1, $this->driver->select('SELECT * FROM a'));

        $counts = $manager->restoreJson($json);
        $this->assertSame(2, $counts['a']);
        $rows = $this->driver->select('SELECT name, qty FROM a ORDER BY qty');
        $this->assertCount(2, $rows);
        $this->assertSame('x', $rows[0]['name']);
        $this->assertSame('y', $rows[1]['name']);
    }

    public function testRestoreRoundTripPreservesJsonColumn(): void
    {
        $manager = new BackupManager($this->driver);
        $json = $manager->backupJson();
        $manager->restoreJson($json);
        $row = $this->driver->selectOne('SELECT data FROM b LIMIT 1');
        $this->assertSame(['k' => 'v'], json_decode((string)$row['data'], true));
    }

    public function testRestoreIgnoresUnknownTables(): void
    {
        $archive = [
            'version' => 1,
            'tables' => [
                'does_not_exist' => ['columns' => ['id'], 'rows' => [['id' => 1]]],
                'a' => ['columns' => ['id', 'name', 'qty'], 'rows' => []],
            ],
        ];
        $counts = (new BackupManager($this->driver))->restore($archive);
        $this->assertArrayNotHasKey('does_not_exist', $counts);
        $this->assertSame(0, $counts['a']);
        $this->assertCount(0, $this->driver->select('SELECT * FROM a'));
    }

    public function testRestoreRejectsInvalidArchive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupManager($this->driver))->restore(['no_tables' => true]);
    }
}
