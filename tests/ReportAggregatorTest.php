<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../reports/ReportAggregator.php';

class ReportAggregatorTest extends TestCase
{
    public function testDerivesMetricsFromBaseCounters(): void
    {
        $m = ReportAggregator::metrics([
            'clicks' => 100,
            'uniques' => 80,
            'bots' => 5,
            'blocked' => 20,
            'leads' => 8,
            'purchases' => 2,
            'conversions' => 10,
            'revenue' => 300.0,
            'cost' => 150.0,
        ]);

        $this->assertSame(100, $m['clicks']);
        $this->assertSame(150.0, $m['profit']);
        $this->assertSame(100.0, $m['roi']);      // 150/150*100
        $this->assertSame(10.0, $m['cr']);        // 10/100*100
        $this->assertSame(3.0, $m['epc']);        // 300/100
        $this->assertSame(1.5, $m['cpc']);        // 150/100
        $this->assertSame(80.0, $m['uniques_ratio']);
        $this->assertEqualsWithDelta(4.17, $m['bot_ratio'], 0.01); // 5/(100+20)
    }

    public function testZeroDenominatorsAreSafe(): void
    {
        $m = ReportAggregator::metrics([]);
        $this->assertSame(0, $m['clicks']);
        $this->assertSame(0.0, $m['roi']);
        $this->assertSame(0.0, $m['cr']);
        $this->assertSame(0.0, $m['epc']);
        $this->assertSame(0.0, $m['bot_ratio']);
    }

    public function testMergeSeriesZeroFillsAndOrders(): void
    {
        $clicks = [
            '2024-01-02' => ['clicks' => 5, 'uniques' => 4, 'cost' => 2.5],
            '2024-01-01' => ['clicks' => 3, 'uniques' => 3, 'cost' => 1.0],
        ];
        $convs = [
            '2024-01-02' => ['conversions' => 1, 'revenue' => 10.0],
            '2024-01-03' => ['conversions' => 2, 'revenue' => 20.0],
        ];

        $series = ReportAggregator::mergeSeries($clicks, $convs);

        $this->assertCount(3, $series);
        $this->assertSame('2024-01-01', $series[0]['bucket']);
        $this->assertSame(3, $series[0]['clicks']);
        $this->assertSame(0, $series[0]['conversions']);   // zero-filled
        $this->assertSame('2024-01-03', $series[2]['bucket']);
        $this->assertSame(0, $series[2]['clicks']);        // zero-filled
        $this->assertSame(2, $series[2]['conversions']);
        $this->assertSame(20.0, $series[2]['revenue']);
    }
}
