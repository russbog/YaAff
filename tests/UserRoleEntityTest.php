<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../entities/User.php';
require_once __DIR__ . '/../entities/Role.php';

class UserRoleEntityTest extends TestCase
{
    public function testPasswordIsHashedAndVerifiable(): void
    {
        $u = new User(['name' => 'alice']);
        $u->setPassword('s3cret');

        $this->assertNotSame('s3cret', $u->passwordHash(), 'plaintext must never be stored');
        $this->assertTrue($u->verifyPassword('s3cret'));
        $this->assertFalse($u->verifyPassword('wrong'));
    }

    public function testVerifyPasswordFalseWhenNoHash(): void
    {
        $u = new User(['name' => 'alice']);
        $this->assertFalse($u->verifyPassword('anything'));
    }

    public function testEnabledDefaultsTrue(): void
    {
        $u = new User(['name' => 'alice']);
        $this->assertTrue($u->enabled());
        $u->set('enabled', false);
        $this->assertFalse($u->enabled());
    }

    public function testExtraPermissionsAcceptsArrayOrCsv(): void
    {
        $u = new User(['name' => 'alice']);
        $u->set('permissions', ['offers.view', 'reports.view']);
        $this->assertSame(['offers.view', 'reports.view'], $u->extraPermissions());

        $u->set('permissions', ' offers.view , reports.view ');
        $this->assertSame(['offers.view', 'reports.view'], $u->extraPermissions());
    }

    public function testRolePermissionsAcceptsArrayOrCsv(): void
    {
        $r = new Role(['name' => 'Analyst']);
        $r->set('permissions', ['*.view']);
        $this->assertSame(['*.view'], $r->permissions());

        $r->set('permissions', '*.view, reports.view');
        $this->assertSame(['*.view', 'reports.view'], $r->permissions());
    }
}
