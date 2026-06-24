<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../reports/ReportExporter.php';

class ReportExporterTest extends TestCase
{
    public function testFlattenNestedTreeCarriesGroupColumns(): void
    {
        $tree = [
            [
                'group' => 'US',
                'clicks' => 30,
                '_children' => [
                    ['group' => 'mobile', 'clicks' => 20],
                    ['group' => 'desktop', 'clicks' => 10],
                ],
            ],
            [
                'group' => 'DE',
                'clicks' => 5,
                '_children' => [
                    ['group' => 'mobile', 'clicks' => 5],
                ],
            ],
        ];

        $rows = ReportExporter::flattenTree($tree, ['country', 'device']);

        $this->assertCount(3, $rows);
        $this->assertSame('US', $rows[0]['country']);
        $this->assertSame('mobile', $rows[0]['device']);
        $this->assertSame(20, $rows[0]['clicks']);
        $this->assertSame('DE', $rows[2]['country']);
        $this->assertSame('mobile', $rows[2]['device']);
        $this->assertSame(5, $rows[2]['clicks']);
    }

    public function testFlattenFlatTree(): void
    {
        $tree = [
            ['group' => 'US', 'clicks' => 10],
            ['group' => 'DE', 'clicks' => 4],
        ];
        $rows = ReportExporter::flattenTree($tree, ['country']);
        $this->assertCount(2, $rows);
        $this->assertSame('US', $rows[0]['country']);
        $this->assertSame(10, $rows[0]['clicks']);
    }

    public function testToCsvQuotesAndEscapes(): void
    {
        $rows = [
            ['country' => 'US', 'note' => 'a,b'],
            ['country' => 'DE', 'note' => 'say "hi"'],
        ];
        $csv = ReportExporter::toCsv($rows);
        $lines = explode("\r\n", trim($csv));

        $this->assertSame('country,note', $lines[0]);
        $this->assertSame('US,"a,b"', $lines[1]);
        $this->assertSame('DE,"say ""hi"""', $lines[2]);
    }

    public function testToCsvRespectsExplicitColumns(): void
    {
        $rows = [['a' => 1, 'b' => 2, 'c' => 3]];
        $csv = ReportExporter::toCsv($rows, ['b', 'a']);
        $lines = explode("\r\n", trim($csv));
        $this->assertSame('b,a', $lines[0]);
        $this->assertSame('2,1', $lines[1]);
    }

    public function testToJson(): void
    {
        $rows = [['country' => 'US', 'clicks' => 10]];
        $this->assertSame('[{"country":"US","clicks":10}]', ReportExporter::toJson($rows));
    }
}
