<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../core.php';

final class DeviceDetectionFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['cloSettings']['cachingDir'] = sys_get_temp_dir();
        $GLOBALS['cloSettings']['devicesCache'] = 'yaaff-device-cache';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    }

    public function testShortUserAgentFallsBackWithoutWarnings(): void
    {
        $params = FiltrationCore::get_click_params([
            'tds_ua' => 'Mozilla/5.0',
            'tds_lang' => 'en-US,en;q=0.9',
        ]);

        $this->assertSame('Unknown', $params['client']);
        $this->assertSame('', $params['clientver']);
        $this->assertArrayHasKey('os', $params);
        $this->assertArrayHasKey('osver', $params);
    }
}
