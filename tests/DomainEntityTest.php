<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../entities/Domain.php';

class DomainEntityTest extends TestCase
{
    public function testHostNormalized(): void
    {
        $d = new Domain(['name' => '  Go.Example.COM ']);
        $this->assertSame('go.example.com', $d->host());
    }

    public function testTypeDefaultsToRegularAndValidates(): void
    {
        $this->assertSame(Domain::TYPE_REGULAR, (new Domain())->type());
        $bad = new Domain(['settings' => ['type' => 'bogus']]);
        $this->assertSame(Domain::TYPE_REGULAR, $bad->type());
        $wild = new Domain(['settings' => ['type' => 'wildcard']]);
        $this->assertSame(Domain::TYPE_WILDCARD, $wild->type());
    }

    public function testAliasOf(): void
    {
        $alias = new Domain(['name' => 'a.com', 'settings' => ['type' => 'alias', 'alias_of' => 'Canonical.com']]);
        $this->assertTrue($alias->isAlias());
        $this->assertSame('canonical.com', $alias->aliasOf());

        $regular = new Domain(['name' => 'b.com', 'settings' => ['alias_of' => 'x.com']]);
        $this->assertFalse($regular->isAlias());
        $this->assertSame('', $regular->aliasOf());
    }

    public function testDnsAndCloudflareAccessors(): void
    {
        $d = new Domain(['settings' => [
            'dns_type' => 'cname',
            'dns_content' => 'target.example.com',
            'dns_proxied' => true,
            'cf_zone_id' => 'z1',
            'cf_api_token' => 'secret',
        ]]);
        $this->assertSame('CNAME', $d->dnsType());
        $this->assertSame('target.example.com', $d->dnsContent());
        $this->assertTrue($d->dnsProxied());
        $this->assertSame('z1', $d->cfZoneId());
        $this->assertSame('secret', $d->cfApiToken());
    }

    public function testDnsTypeFallsBackToA(): void
    {
        $d = new Domain(['settings' => ['dns_type' => 'weird']]);
        $this->assertSame('A', $d->dnsType());
    }

    public function testCampaignId(): void
    {
        $this->assertNull((new Domain())->campaignId());
        $this->assertSame(7, (new Domain(['settings' => ['campaign_id' => '7']]))->campaignId());
    }
}
