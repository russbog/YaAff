<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../auth/Authenticator.php';

class AuthenticatorTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;
    private Authenticator $auth;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytauth') . '.db';
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
        $this->auth = new Authenticator($this->driver);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    private function makeRole(string $name, array $permissions): Role
    {
        $r = new Role(['name' => $name]);
        $r->set('permissions', $permissions);
        return Repositories::roles($this->driver)->save($r);
    }

    private function makeUser(string $name, string $password, string $role, bool $enabled = true): User
    {
        $u = new User(['name' => $name]);
        $u->setPassword($password);
        $u->set('role', $role);
        $u->set('enabled', $enabled);
        return Repositories::users($this->driver)->save($u);
    }

    public function testHasUsersReflectsTable(): void
    {
        $this->assertFalse($this->auth->hasUsers());
        $this->makeUser('alice', 'pw', '');
        $this->assertTrue($this->auth->hasUsers());
    }

    public function testAuthenticateSuccessResolvesRolePermissions(): void
    {
        $role = $this->makeRole('Analyst', ['*.view', 'reports.view']);
        $this->makeUser('alice', 'pw', (string)$role->id);

        $ctx = $this->auth->authenticate('alice', 'pw');
        $this->assertNotNull($ctx);
        $this->assertSame('alice', $ctx['name']);
        $this->assertSame('Analyst', $ctx['role']);
        $this->assertContains('*.view', $ctx['permissions']);
        $this->assertContains('reports.view', $ctx['permissions']);
    }

    public function testAuthenticateFailsOnWrongPassword(): void
    {
        $this->makeUser('alice', 'pw', '');
        $this->assertNull($this->auth->authenticate('alice', 'nope'));
    }

    public function testAuthenticateFailsWhenDisabled(): void
    {
        $role = $this->makeRole('Admin', ['*']);
        $this->makeUser('bob', 'pw', (string)$role->id, false);
        $this->assertNull($this->auth->authenticate('bob', 'pw'));
    }

    public function testRoleResolvableByNameAndExtraPermissionsMerge(): void
    {
        $this->makeRole('Manager', ['offers.*']);
        $u = new User(['name' => 'carol']);
        $u->setPassword('pw');
        $u->set('role', 'Manager');
        $u->set('permissions', ['domains.view']);
        Repositories::users($this->driver)->save($u);

        $ctx = $this->auth->authenticate('carol', 'pw');
        $this->assertNotNull($ctx);
        $this->assertSame('Manager', $ctx['role']);
        $this->assertContains('offers.*', $ctx['permissions']);
        $this->assertContains('domains.view', $ctx['permissions']);
    }

    public function testBuiltinAdminRoleGrantsAllWithoutRoleRow(): void
    {
        // No "admin" role row exists; the built-in fallback still grants "*".
        $this->makeUser('root', 'pw', 'admin');
        $ctx = $this->auth->authenticate('root', 'pw');
        $this->assertNotNull($ctx);
        $this->assertContains('*', $ctx['permissions']);
        $this->assertTrue(AccessControl::permits($ctx['permissions'], 'users.manage'));
    }

    public function testAuthenticateTokenMatchesEnabledUserOnly(): void
    {
        $u = new User(['name' => 'api']);
        $u->setPassword('pw');
        $u->set('api_token', 'TOKEN-123');
        $u->set('role', 'admin');
        Repositories::users($this->driver)->save($u);

        $ctx = $this->auth->authenticateToken('TOKEN-123');
        $this->assertNotNull($ctx);
        $this->assertSame('api', $ctx['name']);

        $this->assertNull($this->auth->authenticateToken('wrong'));
        $this->assertNull($this->auth->authenticateToken(''));
    }
}
