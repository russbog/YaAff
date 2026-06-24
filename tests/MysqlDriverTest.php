<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/MysqlDriver.php';

/**
 * Integration tests for the MySQL driver. They require a reachable MySQL/MariaDB
 * server and the pdo_mysql extension; otherwise every test is skipped, so the
 * suite stays green on machines without a database (e.g. SQLite-only installs).
 *
 * Configure via env: YAAFF_TEST_MYSQL_{HOST,PORT,DB,USER,PASS}.
 */
class MysqlDriverTest extends TestCase
{
    private static bool $available = false;
    /** @var array<string,mixed> */
    private static array $config = [];
    private MysqlDriver $driver;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            return;
        }
        self::$config = [
            'host' => getenv('YAAFF_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('YAAFF_TEST_MYSQL_PORT') ?: 3306),
            'database' => getenv('YAAFF_TEST_MYSQL_DB') ?: 'yaaff_test',
            'username' => getenv('YAAFF_TEST_MYSQL_USER') ?: 'yaaff',
            'password' => getenv('YAAFF_TEST_MYSQL_PASS') ?: 'yaaffpass',
        ];
        try {
            (new MysqlDriver(self::$config))->exec('SELECT 1');
            self::$available = true;
        } catch (Throwable $e) {
            self::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            $this->markTestSkipped('No MySQL/MariaDB server available for integration tests.');
        }
        $this->driver = new MysqlDriver(self::$config);
        $this->driver->exec('DROP TABLE IF EXISTS yt_test');
        // Schema authored in SQLite dialect; the driver translates it.
        $this->driver->exec(
            'CREATE TABLE yt_test (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, qty INTEGER, ratio REAL, settings TEXT)'
        );
    }

    protected function tearDown(): void
    {
        if (self::$available) {
            $this->driver->exec('DROP TABLE IF EXISTS yt_test');
        }
    }

    public function testName(): void
    {
        $this->assertSame('mysql', $this->driver->name());
    }

    public function testSchemaTypesTranslated(): void
    {
        $cols = $this->driver->select('SHOW COLUMNS FROM yt_test');
        $types = [];
        foreach ($cols as $c) {
            $types[strtolower((string)$c['Field'])] = strtolower((string)$c['Type']);
        }
        $this->assertStringContainsString('bigint', $types['id']);
        $this->assertStringContainsString('varchar(191)', $types['name']);
        $this->assertStringContainsString('bigint', $types['qty']);
        $this->assertStringContainsString('double', $types['ratio']);
        $this->assertStringContainsString('longtext', $types['settings']);
    }

    public function testInsertSelectAndLastId(): void
    {
        $id = $this->driver->insert(
            'INSERT INTO yt_test (name, qty) VALUES (?, ?)',
            [['a', DbDriver::TEXT], [3, DbDriver::INT]]
        );
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $this->driver->lastInsertId());

        $row = $this->driver->selectOne('SELECT name, qty FROM yt_test WHERE id = ?', [[$id, DbDriver::INT]]);
        $this->assertSame('a', $row['name']);
        $this->assertSame(3, (int)$row['qty']);
    }

    public function testNamedParameters(): void
    {
        $this->driver->execute(
            'INSERT INTO yt_test (name, qty) VALUES (:n, :q)',
            [':n' => ['x', DbDriver::TEXT], ':q' => [7, DbDriver::INT]]
        );
        $row = $this->driver->selectOne('SELECT qty FROM yt_test WHERE name = :n', [':n' => ['x', DbDriver::TEXT]]);
        $this->assertSame(7, (int)$row['qty']);
    }

    public function testAffectedRows(): void
    {
        $this->driver->insert('INSERT INTO yt_test (name, qty) VALUES (?, ?)', [['a', DbDriver::TEXT], [1, DbDriver::INT]]);
        $this->driver->insert('INSERT INTO yt_test (name, qty) VALUES (?, ?)', [['b', DbDriver::TEXT], [2, DbDriver::INT]]);
        $this->driver->execute('UPDATE yt_test SET qty = 0');
        $this->assertSame(2, $this->driver->affectedRows());
    }

    public function testTransactionRollback(): void
    {
        $this->driver->insert('INSERT INTO yt_test (name) VALUES (?)', [['keep', DbDriver::TEXT]]);
        $this->driver->beginTransaction();
        $this->driver->insert('INSERT INTO yt_test (name) VALUES (?)', [['drop', DbDriver::TEXT]]);
        $this->driver->rollback();
        $this->assertCount(1, $this->driver->select('SELECT * FROM yt_test'));
    }

    public function testJsonExtract(): void
    {
        $this->driver->insert(
            'INSERT INTO yt_test (name, settings) VALUES (?, ?)',
            [['j', DbDriver::TEXT], [json_encode(['k' => 'v', 'amount' => 12.5]), DbDriver::TEXT]]
        );
        $expr = $this->driver->jsonExtract('settings', 'k');
        $row = $this->driver->selectOne("SELECT $expr AS val FROM yt_test WHERE name = ?", [['j', DbDriver::TEXT]]);
        $this->assertSame('v', $row['val']);

        $realExpr = $this->driver->jsonExtractReal('settings', 'amount');
        $row = $this->driver->selectOne("SELECT $realExpr AS val FROM yt_test WHERE name = ?", [['j', DbDriver::TEXT]]);
        $this->assertEquals(12.5, (float)$row['val']);
    }

    public function testTablesAndColumns(): void
    {
        $this->assertContains('yt_test', $this->driver->tables());
        $this->assertSame(['id', 'name', 'qty', 'ratio', 'settings'], $this->driver->tableColumns('yt_test'));
        $this->assertSame([], $this->driver->tableColumns('nope_missing'));
    }

    public function testDialectHelperStrings(): void
    {
        $this->assertStringContainsString('JSON_EXTRACT', $this->driver->jsonExtract('c', 'k'));
        $this->assertSame('INSERT IGNORE INTO', $this->driver->insertIgnoreInto());
        $this->assertSame('', $this->driver->caseInsensitiveCollation());
    }

    public function testInsertIgnoreNoError(): void
    {
        $sql = $this->driver->insertIgnoreInto() . ' yt_test (name) VALUES (?)';
        $this->driver->execute($sql, [['dup', DbDriver::TEXT]]);
        $this->driver->execute($sql, [['dup', DbDriver::TEXT]]);
        $this->assertCount(1, $this->driver->select('SELECT * FROM yt_test WHERE name = ?', [['dup', DbDriver::TEXT]]));
    }
}
