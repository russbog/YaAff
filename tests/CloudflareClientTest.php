<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../domains/CloudflareClient.php';

class CloudflareClientTest extends TestCase
{
    public function testBuildCreateDnsRecordRequest(): void
    {
        $req = CloudflareClient::buildCreateDnsRecordRequest('tok', 'zone1', 'a', 'go.example.com', '1.2.3.4', true);
        $this->assertSame('POST', $req['method']);
        $this->assertSame(CloudflareClient::API_BASE . '/zones/zone1/dns_records', $req['url']);
        $this->assertContains('Authorization: Bearer tok', $req['headers']);
        $body = json_decode($req['body'], true);
        $this->assertSame('A', $body['type']);
        $this->assertSame('go.example.com', $body['name']);
        $this->assertSame('1.2.3.4', $body['content']);
        $this->assertTrue($body['proxied']);
    }

    public function testBuildListZonesRequestWithName(): void
    {
        $req = CloudflareClient::buildListZonesRequest('tok', 'example.com');
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('zones?name=example.com', $req['url']);
    }

    public function testCreateDnsRecordValidatesInputs(): void
    {
        $res = CloudflareClient::createDnsRecord('', 'zone', 'A', 'h', 'c');
        $this->assertFalse($res['ok']);
        $this->assertSame(0, $res['http_code']);
        $this->assertNotSame('', $res['error']);
    }

    public function testParseResponseSuccess(): void
    {
        $raw = json_encode(['success' => true, 'result' => ['id' => 'rec1'], 'errors' => []]);
        $res = CloudflareClient::parseResponse(200, '', $raw);
        $this->assertTrue($res['ok']);
        $this->assertSame('rec1', $res['result']['id']);
    }

    public function testParseResponseApiErrors(): void
    {
        $raw = json_encode(['success' => false, 'result' => null, 'errors' => [['message' => 'bad token'], ['message' => 'nope']]]);
        $res = CloudflareClient::parseResponse(403, '', $raw);
        $this->assertFalse($res['ok']);
        $this->assertSame('bad token; nope', $res['error']);
    }

    public function testParseResponseCurlError(): void
    {
        $res = CloudflareClient::parseResponse(0, 'timeout', '');
        $this->assertFalse($res['ok']);
        $this->assertSame('timeout', $res['error']);
    }

    public function testParseResponseInvalidJson(): void
    {
        $res = CloudflareClient::parseResponse(200, '', 'not-json');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid response', $res['error']);
    }
}
