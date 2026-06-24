<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../auth/AccessControl.php';

class AccessControlTest extends TestCase
{
    public function testExactMatch(): void
    {
        $this->assertTrue(AccessControl::permits(['offers.view'], 'offers.view'));
        $this->assertFalse(AccessControl::permits(['offers.view'], 'offers.manage'));
    }

    public function testSuperWildcardGrantsEverything(): void
    {
        $this->assertTrue(AccessControl::permits(['*'], 'users.manage'));
        $this->assertTrue(AccessControl::permits(['*'], 'anything.at.all'));
    }

    public function testNamespaceWildcard(): void
    {
        $granted = ['offers.*'];
        $this->assertTrue(AccessControl::permits($granted, 'offers.view'));
        $this->assertTrue(AccessControl::permits($granted, 'offers.manage'));
        // also satisfies a query for the namespace itself (menu visibility)
        $this->assertTrue(AccessControl::permits($granted, 'offers'));
        $this->assertFalse(AccessControl::permits($granted, 'domains.view'));
    }

    public function testActionWildcardAcrossNamespaces(): void
    {
        $granted = ['*.view'];
        $this->assertTrue(AccessControl::permits($granted, 'offers.view'));
        $this->assertTrue(AccessControl::permits($granted, 'domains.view'));
        $this->assertFalse(AccessControl::permits($granted, 'offers.manage'));
    }

    public function testEmptyNeededIsAllowed(): void
    {
        $this->assertTrue(AccessControl::permits([], ''));
    }

    public function testEmptyGrantedDeniesConcretePermission(): void
    {
        $this->assertFalse(AccessControl::permits([], 'offers.view'));
    }

    public function testPermitsAny(): void
    {
        $granted = ['reports.view'];
        $this->assertTrue(AccessControl::permitsAny($granted, ['offers.manage', 'reports.view']));
        $this->assertFalse(AccessControl::permitsAny($granted, ['offers.manage', 'domains.view']));
    }
}
