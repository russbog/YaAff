<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../entities/Domain.php';
require_once __DIR__ . '/../domains/DomainStatusChecker.php';
require_once __DIR__ . '/../domains/DomainStatusStore.php';
require_once __DIR__ . '/../domains/AcmeSslManager.php';
require_once __DIR__ . '/../domains/CloudflareClient.php';
require_once __DIR__ . '/../domains/DomainFixer.php';
require_once __DIR__ . '/../domains/ServerIp.php';

/**
 * Pure-logic coverage for the domain status/health feature: the classify state
 * machine, certificate hostname matching, the status store, and the (pure)
 * Cloudflare/acme command builders. No network or root access is required.
 */
final class DomainStatusTest extends TestCase
{
    private const NOW = 1_700_000_000;

    /** @param array<string,mixed> $dns @param array<string,mixed> $ssl */
    private function classify(array $dns, array $ssl): array
    {
        return DomainStatusChecker::classify($dns, $ssl, self::NOW);
    }

    private function dns(bool $resolves, ?string $ip, bool $cf = false, ?string $err = null, bool $applicable = true): array
    {
        return ['applicable' => $applicable, 'resolves' => $resolves, 'ip' => $ip, 'cloudflare' => $cf, 'error' => $err];
    }

    private function ssl(bool $reachable, bool $valid, ?int $expiresAt = null, bool $hostMatch = true, ?string $err = null): array
    {
        return ['reachable' => $reachable, 'valid' => $valid, 'expires_at' => $expiresAt, 'host_match' => $hostMatch, 'error' => $err];
    }

    public function testWildcardOrAliasIsNotApplicable(): void
    {
        $r = $this->classify($this->dns(false, null, false, null, false), $this->ssl(false, false));
        $this->assertSame(DomainStatusChecker::NA, $r['status']);
    }

    public function testNoARecordIsDnsAwait(): void
    {
        $r = $this->classify($this->dns(false, null), $this->ssl(false, false));
        $this->assertSame(DomainStatusChecker::DNS_AWAIT, $r['status']);
        $this->assertFalse($r['needs_fix']);
    }

    public function testResolvesElsewhereIsDnsError(): void
    {
        $r = $this->classify($this->dns(false, '203.0.113.9', false, 'Resolves to 203.0.113.9, but this server is 198.51.100.2'), $this->ssl(false, false));
        $this->assertSame(DomainStatusChecker::DNS_ERROR, $r['status']);
        $this->assertStringContainsString('203.0.113.9', $r['detail']);
    }

    public function testDnsOkButNoHttpsIsSslAwait(): void
    {
        $r = $this->classify($this->dns(true, '198.51.100.2'), $this->ssl(false, false));
        $this->assertSame(DomainStatusChecker::SSL_AWAIT, $r['status']);
        $this->assertTrue($r['needs_fix']);
    }

    public function testExpiredCertIsSslError(): void
    {
        $r = $this->classify($this->dns(true, '198.51.100.2'), $this->ssl(true, false, self::NOW - 86400, true, 'Certificate has expired'));
        $this->assertSame(DomainStatusChecker::SSL_ERROR, $r['status']);
        $this->assertTrue($r['needs_fix']);
        $this->assertStringContainsString('expired', strtolower($r['detail']));
    }

    public function testHostMismatchIsSslError(): void
    {
        $r = $this->classify($this->dns(true, '198.51.100.2'), $this->ssl(true, false, self::NOW + 86400 * 90, false));
        $this->assertSame(DomainStatusChecker::SSL_ERROR, $r['status']);
    }

    public function testValidCertIsOkWithDaysLeft(): void
    {
        $r = $this->classify($this->dns(true, '198.51.100.2'), $this->ssl(true, true, self::NOW + 86400 * 60));
        $this->assertSame(DomainStatusChecker::OK, $r['status']);
        $this->assertSame(60, $r['days_left']);
        $this->assertFalse($r['needs_fix']);
    }

    public function testValidButExpiringSoonFlagsRenewal(): void
    {
        $r = $this->classify($this->dns(true, '198.51.100.2', true), $this->ssl(true, true, self::NOW + 86400 * 10));
        $this->assertSame(DomainStatusChecker::OK, $r['status']);
        $this->assertSame(10, $r['days_left']);
        $this->assertTrue($r['needs_fix']);
        $this->assertTrue($r['cloudflare']);
    }

    public function testCertCoversHostExactAndWildcard(): void
    {
        $exact = ['subject' => ['CN' => 'example.com'], 'extensions' => ['subjectAltName' => 'DNS:example.com, DNS:www.example.com']];
        $this->assertTrue(DomainStatusChecker::certCoversHost($exact, 'www.example.com'));
        $this->assertFalse(DomainStatusChecker::certCoversHost($exact, 'api.example.com'));

        $wild = ['subject' => ['CN' => '*.example.com'], 'extensions' => ['subjectAltName' => 'DNS:*.example.com']];
        $this->assertTrue(DomainStatusChecker::certCoversHost($wild, 'api.example.com'));
        $this->assertFalse(DomainStatusChecker::certCoversHost($wild, 'a.b.example.com'));
        $this->assertFalse(DomainStatusChecker::certCoversHost($wild, 'example.com'));
    }

    public function testStoreRoundTripAndPrune(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yadomstatus') . '.json';
        @unlink($path);
        $store = new DomainStatusStore($path);

        $this->assertSame([], $store->all());
        $store->put(1, ['status' => 'ok', 'host' => 'a.test']);
        $store->put(2, ['status' => 'dns_await', 'host' => 'b.test']);
        $this->assertSame('ok', $store->get(1)['status']);
        $this->assertCount(2, $store->all());

        $store->forget(1);
        $this->assertNull($store->get(1));

        $store->put(3, ['status' => 'ssl_await', 'host' => 'c.test']);
        $store->prune([3]); // only id 3 still exists
        $this->assertSame([3], array_keys($store->all()));

        @unlink($path);
    }

    public function testServerIpValidation(): void
    {
        $this->assertTrue(ServerIp::isPublicIpv4('157.230.223.232'));
        $this->assertFalse(ServerIp::isPublicIpv4('10.0.0.5'));
        $this->assertFalse(ServerIp::isPublicIpv4('127.0.0.1'));
        $this->assertFalse(ServerIp::isPublicIpv4('not-an-ip'));
    }

    public function testAcmeHostSafetyAndPaths(): void
    {
        $this->assertTrue(AcmeSslManager::isSafeHost('example.com'));
        $this->assertTrue(AcmeSslManager::isSafeHost('sub.example.co.uk'));
        $this->assertFalse(AcmeSslManager::isSafeHost('bad host.com'));
        $this->assertFalse(AcmeSslManager::isSafeHost('example.com; rm -rf /'));
        $this->assertFalse(AcmeSslManager::isSafeHost('localhost'));

        $acme = new AcmeSslManager('/root/.acme.sh/acme.sh', '/var/www/yaaff', '/etc/yaaff-ssl', '/etc/nginx/conf.d', '/etc/nginx/snippets/yaaff-app.conf');
        $this->assertStringContainsString("--issue", $acme->issueCommand('example.com'));
        $this->assertStringContainsString("-w '/var/www/yaaff'", $acme->issueCommand('example.com'));
        $this->assertStringContainsString('fullchain.pem', $acme->installCommand('example.com'));

        $vhost = $acme->vhostConfig('example.com');
        $this->assertStringContainsString('listen 443 ssl;', $vhost);
        $this->assertStringContainsString('server_name example.com;', $vhost);
        $this->assertStringContainsString('/etc/yaaff-ssl/example.com/fullchain.pem', $vhost);
        $this->assertStringContainsString('include /etc/nginx/snippets/yaaff-app.conf;', $vhost);
        // Pool domains are traffic-only: the generated vhost must hard-404 the
        // admin panel and the management REST API (defense in depth).
        $this->assertStringContainsString('location ^~ /admin { return 404; }', $vhost);
        $this->assertStringContainsString('location ^~ /api/rest.php { return 404; }', $vhost);
        $this->assertStringContainsString('location ^~ /api/openapi.php { return 404; }', $vhost);
    }

    public function testCloudflareUniversalSslRequestBuilders(): void
    {
        $get = CloudflareClient::buildGetUniversalSslRequest('tok', 'zone123');
        $this->assertSame('GET', $get['method']);
        $this->assertStringContainsString('/zones/zone123/ssl/universal/settings', $get['url']);
        $this->assertContains('Authorization: Bearer tok', $get['headers']);

        $set = CloudflareClient::buildSetUniversalSslRequest('tok', 'zone123', true);
        $this->assertSame('PATCH', $set['method']);
        $this->assertSame('{"enabled":true}', $set['body']);

        $list = CloudflareClient::buildListDnsRecordsRequest('tok', 'zone123', 'example.com', 'A');
        $this->assertSame('GET', $list['method']);
        $this->assertStringContainsString('name=example.com', $list['url']);
        $this->assertStringContainsString('type=A', $list['url']);
    }

    public function testFixerRoutesByCloudflarePresence(): void
    {
        $cf = new Domain(['name' => 'cf.test']);
        $cf->set('cf_api_token', 'tok')->set('cf_zone_id', 'zone');
        $this->assertTrue(DomainFixer::usesCloudflare($cf));

        $direct = new Domain(['name' => 'direct.test']);
        $this->assertFalse(DomainFixer::usesCloudflare($direct));

        // Unprivileged Let's Encrypt path is queued, not executed.
        $fixer = new DomainFixer();
        $res = $fixer->fix($direct, false);
        $this->assertSame('letsencrypt', $res['method']);
        $this->assertTrue($res['queued']);
    }
}
