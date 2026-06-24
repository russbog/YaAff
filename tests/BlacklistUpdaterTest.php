<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bots/BlacklistUpdater.php';

class BlacklistUpdaterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/blu_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testUpdateWritesFetchedFeeds(): void
    {
        $store = new BlacklistStore($this->dir);
        $fetcher = fn(string $url) => ['ok' => true, 'body' => "1.2.3.0/24\n4.4.4.4\n", 'error' => ''];
        $updater = new BlacklistUpdater($store, $fetcher);

        $res = $updater->update([['name' => 'dc', 'type' => 'ip', 'url' => 'http://x']]);

        $this->assertTrue($res[0]['ok']);
        $this->assertSame(2, $res[0]['count']);
        $this->assertTrue($store->matchesIp('1.2.3.55'));
    }

    public function testSkipsDisabledFeed(): void
    {
        $store = new BlacklistStore($this->dir);
        $called = false;
        $fetcher = function (string $url) use (&$called) {
            $called = true;
            return ['ok' => true, 'body' => 'x', 'error' => ''];
        };
        $updater = new BlacklistUpdater($store, $fetcher);

        $res = $updater->update([['name' => 'dc', 'type' => 'ip', 'url' => 'http://x', 'enabled' => false]]);

        $this->assertTrue($res[0]['skipped']);
        $this->assertFalse($called);
    }

    public function testFailedFetchKeepsExistingCache(): void
    {
        $store = new BlacklistStore($this->dir);
        $store->writeFeed('dc', 'ip', ['9.9.9.0/24']);

        $fetcher = fn(string $url) => ['ok' => false, 'body' => '', 'error' => 'timeout'];
        $updater = new BlacklistUpdater($store, $fetcher);
        $res = $updater->update([['name' => 'dc', 'type' => 'ip', 'url' => 'http://x']]);

        $this->assertFalse($res[0]['ok']);
        $this->assertSame('timeout', $res[0]['error']);
        // existing cache preserved
        $this->assertTrue((new BlacklistStore($this->dir))->matchesIp('9.9.9.5'));
    }

    public function testInvalidFeedConfigReported(): void
    {
        $store = new BlacklistStore($this->dir);
        $updater = new BlacklistUpdater($store, fn($u) => ['ok' => true, 'body' => 'x', 'error' => '']);

        $res = $updater->update([['name' => '', 'type' => 'bad', 'url' => '']]);
        $this->assertFalse($res[0]['ok']);
        $this->assertSame('invalid feed config', $res[0]['error']);
    }

    public function testEmptyParsedResultReportedAndNotWritten(): void
    {
        $store = new BlacklistStore($this->dir);
        $updater = new BlacklistUpdater($store, fn($u) => ['ok' => true, 'body' => "# only comments\n", 'error' => '']);

        $res = $updater->update([['name' => 'dc', 'type' => 'ip', 'url' => 'http://x']]);
        $this->assertFalse($res[0]['ok']);
        $this->assertSame('no entries parsed', $res[0]['error']);
        $this->assertFalse(is_file($store->cachePath('dc', 'ip')));
    }

    public function testLoadFeedsParsesJsonWrapperAndPlainArray(): void
    {
        $wrapped = $this->dir . '/feeds.json';
        file_put_contents($wrapped, json_encode(['feeds' => [['name' => 'a', 'type' => 'ip', 'url' => 'u']]]));
        $this->assertCount(1, BlacklistUpdater::loadFeeds($wrapped));

        $plain = $this->dir . '/plain.json';
        file_put_contents($plain, json_encode([['name' => 'b', 'type' => 'ua', 'url' => 'u']]));
        $this->assertCount(1, BlacklistUpdater::loadFeeds($plain));

        $this->assertSame([], BlacklistUpdater::loadFeeds($this->dir . '/missing.json'));
    }
}
