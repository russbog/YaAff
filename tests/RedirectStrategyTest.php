<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../redirects/RedirectStrategy.php';

class RedirectStrategyTest extends TestCase
{
    public function testNormalizeMode(): void
    {
        $this->assertSame('http_302', RedirectStrategy::normalizeMode(302));
        $this->assertSame('http_301', RedirectStrategy::normalizeMode('301'));
        $this->assertSame('js', RedirectStrategy::normalizeMode('js'));
        $this->assertSame('custom_json', RedirectStrategy::normalizeMode('custom_json'));
    }

    public function testClientModeClassification(): void
    {
        foreach (['js', 'meta', 'double_meta', 'blank_referrer', 'formsubmit', 'iframe'] as $m) {
            $this->assertTrue(RedirectStrategy::isClientMode($m), "$m should be client mode");
        }
        $this->assertFalse(RedirectStrategy::isClientMode('http_302'));
        $this->assertFalse(RedirectStrategy::isClientMode('inline'));
    }

    public function testHtmlModeIncludesInline(): void
    {
        $this->assertTrue(RedirectStrategy::isHtmlMode('inline'));
        $this->assertTrue(RedirectStrategy::isHtmlMode('iframe'));
        $this->assertFalse(RedirectStrategy::isHtmlMode('http_302'));
        $this->assertFalse(RedirectStrategy::isHtmlMode('custom_json'));
    }

    public function testJsRenderEscapesUrl(): void
    {
        $html = RedirectStrategy::render('js', 'https://e.com/?a=1&b=2');
        $this->assertStringContainsString('window.location.href=', $html);
        $this->assertSame(1, preg_match('/window\.location\.href=(".*?");/', $html, $m));
        $this->assertSame('https://e.com/?a=1&b=2', json_decode($m[1]));
    }

    public function testMetaRenderHasRefresh(): void
    {
        $html = RedirectStrategy::render('meta', 'https://e.com/');
        $this->assertStringContainsString('http-equiv="refresh"', $html);
        $this->assertStringContainsString('url=https://e.com/', $html);
    }

    public function testDoubleMetaDropsReferrerViaBlank(): void
    {
        $html = RedirectStrategy::render('double_meta', 'https://e.com/');
        $this->assertStringContainsString('about:blank', $html);
        $this->assertStringContainsString('window.location.replace', $html);
    }

    public function testBlankReferrerHasNoReferrerMeta(): void
    {
        $html = RedirectStrategy::render('blank_referrer', 'https://e.com/');
        $this->assertStringContainsString('name="referrer"', $html);
        $this->assertStringContainsString('no-referrer', $html);
    }

    public function testIframeEmbedsTarget(): void
    {
        $html = RedirectStrategy::render('iframe', 'https://e.com/');
        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('src="https://e.com/"', $html);
    }

    public function testFormSubmitPostsData(): void
    {
        $html = RedirectStrategy::render('formsubmit', 'https://e.com/p', [
            'method' => 'POST',
            'data' => ['click_id' => 'abc', 'sum' => '10'],
        ]);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('action="https://e.com/p"', $html);
        $this->assertStringContainsString('name="click_id"', $html);
        $this->assertStringContainsString('value="abc"', $html);
        $this->assertStringContainsString('document.forms[0].submit()', $html);
    }
}
