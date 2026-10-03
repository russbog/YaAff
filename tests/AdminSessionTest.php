<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../cookies.php';

/**
 * @runTestsInSeparateProcesses
 */
final class AdminSessionTest extends TestCase
{
    public function testAdminSessionUsesItsOwnCookieName(): void
    {
        $this->assertSame('PHPSESSID', session_name());

        get_admin_session();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame(ADMIN_SESSION_NAME, session_name());
        $this->assertNotSame('PHPSESSID', ADMIN_SESSION_NAME);
    }

    public function testVisitorSessionKeepsDefaultCookieName(): void
    {
        get_session();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('PHPSESSID', session_name());
    }
}
