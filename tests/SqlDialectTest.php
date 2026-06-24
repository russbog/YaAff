<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqlDialect.php';

class SqlDialectTest extends TestCase
{
    private function translate(string $sql): string
    {
        return (new SqlDialect())->toMysql($sql);
    }

    public function testTypeMappings(): void
    {
        $sql = $this->translate('CREATE TABLE t (id INTEGER, amount NUMERIC, ratio REAL)');
        $this->assertStringContainsString('id BIGINT', $sql);
        $this->assertStringContainsString('amount DECIMAL(20,6)', $sql);
        $this->assertStringContainsString('ratio DOUBLE', $sql);
    }

    public function testAutoincrementConverted(): void
    {
        $sql = $this->translate('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringNotContainsString('AUTOINCREMENT ', $sql);
    }

    public function testEngineAndCharsetAppended(): void
    {
        $sql = $this->translate('CREATE TABLE t (id INTEGER)');
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('CHARSET=utf8mb4', $sql);
    }

    public function testUnindexedTextBecomesLongtext(): void
    {
        $sql = $this->translate('CREATE TABLE t (id INTEGER, settings TEXT)');
        $this->assertStringContainsString('settings LONGTEXT', $sql);
    }

    public function testIndexedTextBecomesVarcharViaInlineUnique(): void
    {
        $sql = $this->translate('CREATE TABLE t (id INTEGER, name TEXT UNIQUE)');
        $this->assertStringContainsString('name VARCHAR(191)', $sql);
        $this->assertStringNotContainsString('name LONGTEXT', $sql);
    }

    public function testTextInPrimaryKeyConstraintBecomesVarchar(): void
    {
        $sql = $this->translate('CREATE TABLE t (k TEXT, v TEXT, PRIMARY KEY (k))');
        $this->assertStringContainsString('k VARCHAR(191)', $sql);
        $this->assertStringContainsString('v LONGTEXT', $sql);
    }

    public function testCollateNocaseStripped(): void
    {
        $sql = $this->translate('CREATE TABLE t (name TEXT COLLATE NOCASE UNIQUE)');
        $this->assertStringNotContainsString('NOCASE', $sql);
        $this->assertStringContainsString('name VARCHAR(191)', $sql);
    }

    public function testCreateIndexOnTextEmitsAlterModify(): void
    {
        // Table first (column unknown to index -> LONGTEXT), index in same batch.
        $batch = "CREATE TABLE t (id INTEGER, token TEXT);\n"
            . "CREATE INDEX idx_t_token ON t (token);";
        $sql = $this->translate($batch);
        // Because the index references token, pass 1 marks it indexed, so the
        // column is emitted directly as VARCHAR (no ALTER needed).
        $this->assertStringContainsString('token VARCHAR(191)', $sql);
    }

    public function testCreateIndexInSeparateBatchEmitsAlter(): void
    {
        $dialect = new SqlDialect();
        $create = $dialect->toMysql('CREATE TABLE t (id INTEGER, token TEXT)');
        $this->assertStringContainsString('token LONGTEXT', $create);

        $index = $dialect->toMysql('CREATE INDEX idx_t_token ON t (token)');
        $this->assertStringContainsString('ALTER TABLE t MODIFY token VARCHAR(191)', $index);
        $this->assertStringContainsString('CREATE INDEX idx_t_token ON t (token)', $index);
    }

    public function testInsertOrIgnoreConverted(): void
    {
        $sql = $this->translate("INSERT OR IGNORE INTO t (id) VALUES (1)");
        $this->assertStringContainsString('INSERT IGNORE INTO', $sql);
        $this->assertStringNotContainsString('INSERT OR IGNORE', $sql);
    }

    public function testPragmaAndTransactionLinesStripped(): void
    {
        $batch = "PRAGMA journal_mode=WAL;\nBEGIN;\nCREATE TABLE t (id INTEGER);\nCOMMIT;";
        $sql = $this->translate($batch);
        $this->assertStringNotContainsString('PRAGMA', $sql);
        $this->assertStringNotContainsString('BEGIN', $sql);
        $this->assertStringNotContainsString('COMMIT', $sql);
        $this->assertStringContainsString('CREATE TABLE t', $sql);
    }

    public function testIfNotExistsPreserved(): void
    {
        $sql = $this->translate('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS t', $sql);
    }

    public function testMultipleStatementsJoined(): void
    {
        $batch = "CREATE TABLE a (id INTEGER);\nCREATE TABLE b (id INTEGER);";
        $sql = $this->translate($batch);
        $this->assertSame(2, substr_count($sql, 'CREATE TABLE'));
    }

    public function testForeignKeyConstraintColumnBecomesVarchar(): void
    {
        $sql = $this->translate(
            'CREATE TABLE t (id INTEGER, ref TEXT, body TEXT, FOREIGN KEY (ref) REFERENCES other(code))'
        );
        $this->assertStringContainsString('ref VARCHAR(191)', $sql);
        $this->assertStringContainsString('body LONGTEXT', $sql);
    }

    public function testNotNullAndDefaultPreservedOnText(): void
    {
        $sql = $this->translate("CREATE TABLE t (id INTEGER, name TEXT NOT NULL DEFAULT '' UNIQUE)");
        $this->assertStringContainsString('VARCHAR(191)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString("DEFAULT ''", $sql);
    }

    public function testGenericDmlUntouched(): void
    {
        $sql = $this->translate('UPDATE t SET name = ? WHERE id = ?');
        $this->assertSame('UPDATE t SET name = ? WHERE id = ?', $sql);
    }
}
