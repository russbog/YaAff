<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../db/Migrator.php';

class MigratorTest extends TestCase
{
    private string $path;
    private string $migDir;
    private SqliteDriver $driver;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytmig') . '.db';
        $this->migDir = sys_get_temp_dir() . '/ytmig_' . uniqid();
        mkdir($this->migDir);
        $this->writeMigration('001_create_alpha', 'alpha');
        $this->writeMigration('002_create_beta', 'beta');

        $this->driver = new SqliteDriver($this->path);
        $this->migrator = new Migrator($this->driver, $this->migDir);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
        foreach (glob($this->migDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->migDir);
    }

    private function writeMigration(string $filename, string $table): void
    {
        $code = <<<PHP
        <?php
        return new class implements Migration {
            public function up(DbDriver \$d): void {
                \$d->exec("CREATE TABLE IF NOT EXISTS $table (id INTEGER PRIMARY KEY)");
            }
            public function down(DbDriver \$d): void {
                \$d->exec("DROP TABLE IF EXISTS $table");
            }
        };
        PHP;
        file_put_contents($this->migDir . '/' . $filename . '.php', $code);
    }

    public function testDiscoverOrdersByVersion(): void
    {
        $versions = array_keys($this->migrator->discover());
        $this->assertSame([1, 2], $versions);
    }

    public function testMigrateAppliesAllPending(): void
    {
        $applied = $this->migrator->migrate();
        $this->assertSame([1, 2], $applied);
        $this->assertSame(2, $this->migrator->currentVersion());
        $this->assertSame(['id'], $this->driver->tableColumns('alpha'));
        $this->assertSame(['id'], $this->driver->tableColumns('beta'));
    }

    public function testMigrateIsIdempotent(): void
    {
        $this->migrator->migrate();
        $this->assertSame([], $this->migrator->migrate());
        $this->assertSame([], array_keys($this->migrator->pending()));
    }

    public function testMigrateAppliesOnlyNewlyAddedMigration(): void
    {
        $this->migrator->migrate();
        $this->writeMigration('003_create_gamma', 'gamma');
        $applied = $this->migrator->migrate();
        $this->assertSame([3], $applied);
        $this->assertSame(['id'], $this->driver->tableColumns('gamma'));
    }

    public function testRollbackRevertsMostRecent(): void
    {
        $this->migrator->migrate();
        $reverted = $this->migrator->rollback(1);
        $this->assertSame([2], $reverted);
        $this->assertSame(1, $this->migrator->currentVersion());
        $this->assertSame([], $this->driver->tableColumns('beta'));
        $this->assertSame(['id'], $this->driver->tableColumns('alpha'));
    }

    public function testStatusReflectsAppliedAndPending(): void
    {
        $this->migrator->migrate();
        $this->writeMigration('003_create_gamma', 'gamma');
        $this->assertSame([1, 2], $this->migrator->appliedVersions());
        $this->assertSame([3], array_keys($this->migrator->pending()));
    }
}
