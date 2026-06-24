<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../entities/Integration.php';

class IntegrationEntityTest extends TestCase
{
    private function make(array $settings): Integration
    {
        return new Integration([
            'id' => 1,
            'name' => 'Test',
            'settings' => json_encode($settings),
        ]);
    }

    public function testMethodDefaultsToPost(): void
    {
        $i = $this->make([]);
        $this->assertSame('POST', $i->method());
    }

    public function testMethodGetNormalized(): void
    {
        $i = $this->make(['method' => 'get']);
        $this->assertSame('GET', $i->method());
    }

    public function testMethodInvalidDefaultsPost(): void
    {
        $i = $this->make(['method' => 'PATCH']);
        $this->assertSame('POST', $i->method());
    }

    public function testTypeDefault(): void
    {
        $i = $this->make([]);
        $this->assertSame('generic', $i->type());
    }

    public function testUrlReturnsValue(): void
    {
        $i = $this->make(['url' => 'https://example.com/{clickid}']);
        $this->assertSame('https://example.com/{clickid}', $i->url());
    }

    public function testHeadersFromArray(): void
    {
        $i = $this->make(['headers' => ['Auth' => 'Bearer X']]);
        $this->assertSame(['Auth' => 'Bearer X'], $i->headers());
    }

    public function testHeadersFromJsonString(): void
    {
        $i = $this->make(['headers' => '{"X-Custom":"val"}']);
        $this->assertSame(['X-Custom' => 'val'], $i->headers());
    }

    public function testStatusesFromArray(): void
    {
        $i = $this->make(['statuses' => ['Lead', 'Purchase']]);
        $this->assertSame(['Lead', 'Purchase'], $i->statuses());
    }

    public function testStatusesFromCsvString(): void
    {
        $i = $this->make(['statuses' => 'Lead, Purchase, Sale']);
        $this->assertSame(['Lead', 'Purchase', 'Sale'], $i->statuses());
    }

    public function testFiresForMatchesCaseInsensitive(): void
    {
        $i = $this->make(['statuses' => ['Lead', 'Purchase'], 'enabled' => true]);
        $this->assertTrue($i->firesFor('lead'));
        $this->assertTrue($i->firesFor('PURCHASE'));
        $this->assertFalse($i->firesFor('Reject'));
    }

    public function testFiresForEmptyStatusesMeansAll(): void
    {
        $i = $this->make(['statuses' => [], 'enabled' => true]);
        $this->assertTrue($i->firesFor('anything'));
    }

    public function testFiresForDisabledNeverFires(): void
    {
        $i = $this->make(['statuses' => [], 'enabled' => false]);
        $this->assertFalse($i->firesFor('Lead'));
    }

    public function testEnabledDefaultTrue(): void
    {
        $i = $this->make([]);
        $this->assertTrue($i->enabled());
    }

    public function testContentTypeDefault(): void
    {
        $i = $this->make([]);
        $this->assertSame('application/json', $i->contentType());
    }

    public function testBodyReturnsTemplate(): void
    {
        $i = $this->make(['body' => '{"x":"{status}"}']);
        $this->assertSame('{"x":"{status}"}', $i->body());
    }
}
