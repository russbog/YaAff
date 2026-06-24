<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../api/ApiAuth.php';
require_once __DIR__ . '/../entities/Repositories.php';

class ApiAuthTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytapiauth') . '.db';
        $this->driver = new SqliteDriver($this->path);
        foreach (['roles', 'users'] as $table) {
            $this->driver->exec(
                "CREATE TABLE $table (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    group_id INTEGER,
                    settings TEXT NOT NULL DEFAULT '{}',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )"
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testEmptyTokenIsNull(): void
    {
        $this->assertNull(ApiAuth::resolve($this->driver, '', 'master'));
    }

    public function testMasterTokenGrantsSuperAdmin(): void
    {
        $ctx = ApiAuth::resolve($this->driver, 'master', 'master');
        $this->assertNotNull($ctx);
        $this->assertSame(['*'], $ctx['permissions']);
        $this->assertSame('api', $ctx['name']);
    }

    public function testMasterTokenDisabledWhenEmpty(): void
    {
        $this->assertNull(ApiAuth::resolve($this->driver, 'anything', ''));
    }

    public function testPerUserApiToken(): void
    {
        $u = new User(['name' => 'svc']);
        $u->set('enabled', true);
        $u->set('api_token', 'tok-123');
        $u->set('permissions', ['offers.view']);
        Repositories::users($this->driver)->save($u);

        $ctx = ApiAuth::resolve($this->driver, 'tok-123', 'master');
        $this->assertNotNull($ctx);
        $this->assertSame('svc', $ctx['name']);
        $this->assertContains('offers.view', $ctx['permissions']);

        $this->assertNull(ApiAuth::resolve($this->driver, 'wrong', 'master'));
    }
}
