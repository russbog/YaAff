<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../integrations/ConversionApiSender.php';

class ConversionApiSenderTest extends TestCase
{
    public function testRenderTemplateBasic(): void
    {
        $tokens = ['clickid' => 'abc123', 'status' => 'Lead', 'payout' => 5.5];
        $template = '{"click":"{clickid}","event":"{status}","value":{payout}}';
        $result = ConversionApiSender::renderTemplate($template, $tokens);
        $this->assertSame('{"click":"abc123","event":"Lead","value":5.5}', $result);
    }

    public function testRenderTemplateUnknownTokensUntouched(): void
    {
        $result = ConversionApiSender::renderTemplate('hello {unknown} world', ['foo' => 'bar']);
        $this->assertSame('hello {unknown} world', $result);
    }

    public function testRenderTemplateEmptyStringShortCircuits(): void
    {
        $this->assertSame('', ConversionApiSender::renderTemplate('', ['a' => 'b']));
    }

    public function testRenderTemplateNoBracesShortCircuits(): void
    {
        $this->assertSame('literal', ConversionApiSender::renderTemplate('literal', ['a' => 'b']));
    }

    public function testRenderTemplateCustomParams(): void
    {
        $tokens = ['c.fbc' => 'fb.1.123', 'c.fbp' => 'fb.2.456'];
        $result = ConversionApiSender::renderTemplate('"fbc":"{c.fbc}","fbp":"{c.fbp}"', $tokens);
        $this->assertSame('"fbc":"fb.1.123","fbp":"fb.2.456"', $result);
    }

    public function testBuildTokensFlattenClick(): void
    {
        $click = [
            'clickid' => 'CLK1',
            'ip' => '1.2.3.4',
            'country' => 'US',
            'ua' => 'Mozilla/5.0',
            'params' => json_encode(['fbc' => 'fb.val', 'sub1' => 'x']),
            'path' => '/some/path',
        ];
        $tokens = ConversionApiSender::buildTokens($click, 'Purchase', 10.0, 12.0, 'USD', ['tid' => 'TX1']);
        $this->assertSame('CLK1', $tokens['clickid']);
        $this->assertSame('1.2.3.4', $tokens['ip']);
        $this->assertSame('US', $tokens['country']);
        $this->assertSame('Mozilla/5.0', $tokens['ua']);
        $this->assertSame('fb.val', $tokens['c.fbc']);
        $this->assertSame('x', $tokens['c.sub1']);
        $this->assertSame('Purchase', $tokens['status']);
        $this->assertSame(10.0, $tokens['payout']);
        $this->assertSame(12.0, $tokens['revenue']);
        $this->assertSame('USD', $tokens['currency']);
        $this->assertSame('TX1', $tokens['tid']);
        $this->assertArrayNotHasKey('path', $tokens);
    }

    public function testBuildTokensParamsAsArray(): void
    {
        $click = ['clickid' => 'C1', 'params' => ['key1' => 'val1']];
        $tokens = ConversionApiSender::buildTokens($click, 'Lead', 0, 0, 'EUR');
        $this->assertSame('val1', $tokens['c.key1']);
    }

    public function testBuildRequestPost(): void
    {
        $integ = new Integration([
            'name' => 'TestInteg',
            'settings' => json_encode([
                'method' => 'POST',
                'url' => 'https://example.com/api?cid={clickid}',
                'headers' => ['Authorization' => 'Bearer TOKEN123'],
                'body' => '{"event":"{status}","value":{payout}}',
                'content_type' => 'application/json',
                'statuses' => ['Lead', 'Purchase'],
                'enabled' => true,
            ]),
        ]);
        $tokens = ['clickid' => 'ABC', 'status' => 'Lead', 'payout' => 3.5];
        $req = ConversionApiSender::buildRequest($integ, $tokens);
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://example.com/api?cid=ABC', $req['url']);
        $this->assertSame('{"event":"Lead","value":3.5}', $req['body']);
        $this->assertContains('Authorization: Bearer TOKEN123', $req['headers']);
        $this->assertContains('Content-Type: application/json', $req['headers']);
    }

    public function testBuildRequestGet(): void
    {
        $integ = new Integration([
            'name' => 'GetInteg',
            'settings' => json_encode([
                'method' => 'GET',
                'url' => 'https://example.com/track?click={clickid}&status={status}',
                'headers' => [],
                'body' => '',
                'enabled' => true,
            ]),
        ]);
        $tokens = ['clickid' => 'X1', 'status' => 'Purchase'];
        $req = ConversionApiSender::buildRequest($integ, $tokens);
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://example.com/track?click=X1&status=Purchase', $req['url']);
        $this->assertSame('', $req['body']);
    }

    public function testSendReturnsGracefulOnEmptyUrl(): void
    {
        $integ = new Integration([
            'name' => 'Empty',
            'settings' => json_encode(['url' => '', 'method' => 'POST', 'enabled' => true]),
        ]);
        $result = ConversionApiSender::send($integ, []);
        $this->assertSame(0, $result['http_code']);
        $this->assertSame('empty url', $result['error']);
    }
}
