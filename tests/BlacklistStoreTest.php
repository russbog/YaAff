<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bots/BlacklistStore.php';

class BlacklistStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bl_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testEmptyStoreMatchesNothing(): void
    {
        $store = new BlacklistStore($this->dir);
        $this->assertFalse($store->matchesIp('1.2.3.4'));
        $this->assertFalse($store->matchesUa('Googlebot'));
    }

    public function testWriteAndMatchIpCidrAndExact(): void
    {
        $store = new BlacklistStore($this->dir);
        $store->writeFeed('dc', 'ip', ['10.0.0.0/8', '8.8.8.8']);
        $this->assertTrue($store->matchesIp('10.5.6.7'));
        $this->assertTrue($store->matchesIp('8.8.8.8'));
        $this->assertFalse($store->matchesIp('192.168.1.1'));
    }

    public function testWriteAndMatchUaCaseInsensitiveSubstring(): void
    {
        $store = new BlacklistStore($this->dir);
        $store->writeFeed('bots', 'ua', ['googlebot', 'curl']);
        $this->assertTrue($store->matchesUa('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        $this->assertTrue($store->matchesUa('curl/8.0'));
        $this->assertFalse($store->matchesUa('Mozilla/5.0 (Windows NT 10.0)'));
    }

    public function testAggregatesMultipleFeedFilesOfSameType(): void
    {
        $store = new BlacklistStore($this->dir);
        $store->writeFeed('a', 'ip', ['1.1.1.0/24']);
        $store->writeFeed('b', 'ip', ['2.2.2.2']);
        $this->assertTrue($store->matchesIp('1.1.1.55'));
        $this->assertTrue($store->matchesIp('2.2.2.2'));
    }

    public function testWriteFeedReloadsCache(): void
    {
        $store = new BlacklistStore($this->dir);
        $this->assertFalse($store->matchesIp('5.5.5.5'));
        $store->writeFeed('x', 'ip', ['5.5.5.5']);
        $this->assertTrue($store->matchesIp('5.5.5.5'));
    }

    public function testCachePathSanitizesFeedName(): void
    {
        $store = new BlacklistStore($this->dir);
        $path = $store->cachePath('../evil name', 'ip');
        $this->assertStringStartsWith($this->dir . '/', $path);
        $this->assertStringNotContainsString('/../', $path);
        $this->assertStringEndsWith('.ip', $path);
    }
}
