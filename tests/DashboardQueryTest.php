<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../reports/DashboardQuery.php';

class DashboardQueryTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytdash') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec('CREATE TABLE clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, userid TEXT, country TEXT, flow TEXT, cost NUMERIC DEFAULT 0)');
        $this->driver->exec('CREATE TABLE blocked (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, reason TEXT)');
        $this->driver->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, status TEXT, revenue NUMERIC DEFAULT 0)');

        $t = 1700000000;
        // campaign 1: 3 clicks (2 unique users), US/DE, cost 3.0
        $this->click(1, $t, 'u1', 'US', 'flowA', 1.0);
        $this->click(1, $t + 10, 'u1', 'US', 'flowA', 1.0);
        $this->click(1, $t + 20, 'u2', 'DE', 'flowB', 1.0);
        // campaign 2: 1 click
        $this->click(2, $t + 30, 'u3', 'FR', 'flowA', 5.0);
        // blocked: 2 rows for camp 1, one is a bot
        $this->driver->insert('INSERT INTO blocked (campaign_id, time, reason) VALUES (?, ?, ?)', [[1, DbDriver::INT], [$t, DbDriver::INT], ['bot', DbDriver::TEXT]]);
        $this->driver->insert('INSERT INTO blocked (campaign_id, time, reason) VALUES (?, ?, ?)', [[1, DbDriver::INT], [$t, DbDriver::INT], ['geo', DbDriver::TEXT]]);
        // conversions: camp 1 -> 1 lead + 1 purchase, revenue 30
        $this->conv(1, $t + 5, 'Lead', 0.0);
        $this->conv(1, $t + 15, 'Purchase', 30.0);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->path . $s);
        }
    }

    private function click(int $camp, int $time, string $uid, string $country, string $flow, float $cost): void
    {
        $this->driver->insert(
            'INSERT INTO clicks (campaign_id, time, userid, country, flow, cost) VALUES (?, ?, ?, ?, ?, ?)',
            [[$camp, DbDriver::INT], [$time, DbDriver::INT], [$uid, DbDriver::TEXT], [$country, DbDriver::TEXT], [$flow, DbDriver::TEXT], [$cost, DbDriver::FLOAT]]
        );
    }

    private function conv(int $camp, int $time, string $status, float $revenue): void
    {
        $this->driver->insert(
            'INSERT INTO conversions (campaign_id, time, status, revenue) VALUES (?, ?, ?, ?)',
            [[$camp, DbDriver::INT], [$time, DbDriver::INT], [$status, DbDriver::TEXT], [$revenue, DbDriver::FLOAT]]
        );
    }

    public function testSummaryForSingleCampaign(): void
    {
        $q = new DashboardQuery($this->driver);
        $s = $q->summary(1, 1699999999, 1700001000);

        $this->assertSame(3, $s['clicks']);
        $this->assertSame(2, $s['uniques']);
        $this->assertSame(1, $s['bots']);
        $this->assertSame(2, $s['blocked']);
        $this->assertSame(2, $s['conversions']);
        $this->assertSame(1, $s['leads']);
        $this->assertSame(1, $s['purchases']);
        $this->assertSame(30.0, $s['revenue']);
        $this->assertSame(3.0, $s['cost']);
        $this->assertSame(27.0, $s['profit']);
    }

    public function testSummaryAllCampaigns(): void
    {
        $q = new DashboardQuery($this->driver);
        $s = $q->summary(0, 1699999999, 1700001000);
        $this->assertSame(4, $s['clicks']);
        $this->assertSame(3, $s['uniques']);
        $this->assertSame(8.0, $s['cost']);
    }

    public function testTopByCountry(): void
    {
        $q = new DashboardQuery($this->driver);
        $top = $q->topBy('country', 1, 1699999999, 1700001000, 10);
        $this->assertSame('US', $top[0]['name']);
        $this->assertSame(2, $top[0]['clicks']);
        $this->assertSame('DE', $top[1]['name']);
    }

    public function testTopByRejectsUnknownField(): void
    {
        $q = new DashboardQuery($this->driver);
        $this->assertSame([], $q->topBy('ua; DROP TABLE clicks', 1, 0, PHP_INT_MAX));
    }

    public function testTimeseriesBucketsByDay(): void
    {
        $q = new DashboardQuery($this->driver);
        $series = $q->timeseries(1, 1699999999, 1700001000, '+00:00');
        $this->assertNotEmpty($series);
        $totalClicks = array_sum(array_column($series, 'clicks'));
        $totalConvs = array_sum(array_column($series, 'conversions'));
        $this->assertSame(3, $totalClicks);
        $this->assertSame(2, $totalConvs);
    }

    public function testMetricsTimeseriesDerivesKpisPerBucket(): void
    {
        $q = new DashboardQuery($this->driver);
        $series = $q->metricsTimeseries(1, 1699999999, 1700001000, '+00:00', 'day');
        $this->assertNotEmpty($series);

        // All clicks/conversions fall in one day bucket here.
        $bucket = $series[0];
        $this->assertSame(3, $bucket['clicks']);
        $this->assertSame(2, $bucket['uniques']);
        $this->assertSame(2, $bucket['conversions']);
        $this->assertSame(30.0, $bucket['revenue']);
        $this->assertSame(3.0, $bucket['cost']);
        $this->assertSame(27.0, $bucket['profit']);
        // roi = profit/cost*100 = 900; cr = conv/clicks*100 ≈ 66.67
        $this->assertSame(900.0, $bucket['roi']);
        $this->assertEqualsWithDelta(66.67, $bucket['cr'], 0.01);
        $this->assertArrayHasKey('bucket', $bucket);
    }

    public function testMetricsTimeseriesHourGranularityIsFinerThanDay(): void
    {
        $q = new DashboardQuery($this->driver);
        // Two clicks 2 hours apart should land in distinct hour buckets.
        $this->click(1, 1700100000, 'h1', 'US', 'flowA', 0.0);
        $this->click(1, 1700107200, 'h2', 'US', 'flowA', 0.0);
        $hourly = $q->metricsTimeseries(1, 1700099000, 1700110000, '+00:00', 'hour');
        $daily = $q->metricsTimeseries(1, 1700099000, 1700110000, '+00:00', 'day');
        $this->assertGreaterThan(count($daily), count($hourly));
    }
}
