<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../campaign.php';

class ConversionDedupTest extends TestCase
{
    private function makePostbackSettings(string $dedupKey): PostbackSettings
    {
        $ps = new PostbackSettings();
        $ps->dedupKey = $dedupKey;
        return $ps;
    }

    public function testBuildDedupKeyDefault(): void
    {
        $ps = $this->makePostbackSettings('clickid_tid');
        $this->assertSame('CLK1|TX1', $ps->buildDedupKey('CLK1', 'TX1'));
    }

    public function testBuildDedupKeyClickidOnly(): void
    {
        $ps = $this->makePostbackSettings('clickid');
        $this->assertSame('CLK1', $ps->buildDedupKey('CLK1', 'TX1'));
    }

    public function testBuildDedupKeyTidOnly(): void
    {
        $ps = $this->makePostbackSettings('tid');
        $this->assertSame('TX1', $ps->buildDedupKey('CLK1', 'TX1'));
    }

    public function testBuildDedupKeyEmptyTid(): void
    {
        $ps = $this->makePostbackSettings('clickid_tid');
        $this->assertSame('CLK1|', $ps->buildDedupKey('CLK1', ''));
    }
}
