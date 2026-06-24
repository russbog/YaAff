<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';

class SqliteDriverTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytdrv') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER, data TEXT)');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testName(): void
    {
        $this->assertSame('sqlite', $this->driver->name());
    }

    public function testInsertReturnsIdAndLastInsertId(): void
    {
        $id = $this->driver->insert('INSERT INTO t (name, qty) VALUES (?, ?)', [['a', DbDriver::TEXT], [3, DbDriver::INT]]);
        $this->assertSame(1, $id);
        $this->assertSame(1, $this->driver->lastInsertId());
    }

    public function testSelectAndSelectOne(): void
    {
        $this->driver->insert('INSERT INTO t (name, qty) VALUES (?, ?)', [['a', DbDriver::TEXT], [1, DbDriver::INT]]);
        $this->driver->insert('INSERT INTO t (name, qty) VALUES (?, ?)', [['b', DbDriver::TEXT], [2, DbDriver::INT]]);

        $rows = $this->driver->select('SELECT name, qty FROM t ORDER BY qty');
        $this->assertCount(2, $rows);
        $this->assertSame('a', $rows[0]['name']);

        $one = $this->driver->selectOne('SELECT name FROM t WHERE qty = ?', [[2, DbDriver::INT]]);
        $this->assertSame('b', $one['name']);

        $this->assertSame([], $this->driver->selectOne('SELECT name FROM t WHERE qty = ?', [[99, DbDriver::INT]]));
    }

    public function testNamedParameters(): void
    {
        $this->driver->execute('INSERT INTO t (name, qty) VALUES (:n, :q)', [':n' => ['x', DbDriver::TEXT], ':q' => [7, DbDriver::INT]]);
        $row = $this->driver->selectOne('SELECT qty FROM t WHERE name = :n', [':n' => ['x', DbDriver::TEXT]]);
        $this->assertSame(7, (int)$row['qty']);
    }

    public function testAffectedRows(): void
    {
        $this->driver->insert('INSERT INTO t (name, qty) VALUES (?, ?)', [['a', DbDriver::TEXT], [1, DbDriver::INT]]);
        $this->driver->insert('INSERT INTO t (name, qty) VALUES (?, ?)', [['a', DbDriver::TEXT], [2, DbDriver::INT]]);
        $this->driver->execute('UPDATE t SET qty = 0 WHERE name = ?', [['a', DbDriver::TEXT]]);
        $this->assertSame(2, $this->driver->affectedRows());
    }

    public function testTransactionCommitAndRollback(): void
    {
        $this->driver->beginTransaction();
        $this->driver->insert('INSERT INTO t (name) VALUES (?)', [['keep', DbDriver::TEXT]]);
        $this->driver->commit();

        $this->driver->beginTransaction();
        $this->driver->insert('INSERT INTO t (name) VALUES (?)', [['drop', DbDriver::TEXT]]);
        $this->driver->rollback();

        $rows = $this->driver->select('SELECT name FROM t');
        $this->assertCount(1, $rows);
        $this->assertSame('keep', $rows[0]['name']);
    }

    public function testTableColumns(): void
    {
        $this->assertSame(['id', 'name', 'qty', 'data'], $this->driver->tableColumns('t'));
        $this->assertSame([], $this->driver->tableColumns('does_not_exist'));
    }

    public function testJsonExtractInQuery(): void
    {
        $this->driver->insert('INSERT INTO t (name, data) VALUES (?, ?)', [['j', DbDriver::TEXT], [json_encode(['k' => 'v', 'amount' => 12.5]), DbDriver::TEXT]]);
        $expr = $this->driver->jsonExtract('data', 'k');
        $row = $this->driver->selectOne("SELECT $expr AS val FROM t WHERE name = ?", [['j', DbDriver::TEXT]]);
        $this->assertSame('v', $row['val']);

        $realExpr = $this->driver->jsonExtractReal('data', 'amount');
        $row = $this->driver->selectOne("SELECT $realExpr AS val FROM t WHERE name = ?", [['j', DbDriver::TEXT]]);
        $this->assertEquals(12.5, $row['val']);
    }

    public function testDialectHelperStrings(): void
    {
        $this->assertStringContainsString('json_extract', $this->driver->jsonExtract('c', 'k'));
        $this->assertStringContainsString('strftime', $this->driver->dateGroup('time', '+00:00'));
        $this->assertSame('INSERT OR IGNORE INTO', $this->driver->insertIgnoreInto());
        $this->assertSame('COLLATE NOCASE', $this->driver->caseInsensitiveCollation());
        $this->assertSame('MAX(a, b)', $this->driver->greatest('a', 'b'));
    }
}
