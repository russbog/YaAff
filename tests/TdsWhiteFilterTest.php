<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tds.php';

final class TdsWhiteFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['cloSettings']['cachingDir'] = sys_get_temp_dir();
        $GLOBALS['cloSettings']['devicesCache'] = 'yaaff-device-cache';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    }

    private function matchesWhite(FiltrationCore $core, array $filters): bool
    {
        $method = new ReflectionMethod(Tds::class, 'click_matches_white_filters');
        $method->setAccessible(true);
        return $method->invoke(null, $core, $filters);
    }

    public function testEmptyWhiteFiltersDoNotMatchEveryClick(): void
    {
        $core = new FiltrationCore();

        $this->assertFalse($this->matchesWhite($core, []));
        $this->assertFalse($this->matchesWhite($core, ['condition' => 'AND', 'rules' => []]));
    }

    public function testConfiguredWhiteFiltersStillMatchNormally(): void
    {
        $core = new FiltrationCore();
        $filters = [
            'condition' => 'AND',
            'rules' => [[
                'id' => 'ua',
                'field' => 'ua',
                'type' => 'string',
                'input' => 'text',
                'operator' => 'contains',
                'value' => 'Chrome',
            ]],
        ];

        $this->assertTrue($this->matchesWhite($core, $filters));
    }
}
