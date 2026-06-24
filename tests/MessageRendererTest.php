<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../notifications/MessageRenderer.php';

class MessageRendererTest extends TestCase
{
    public function testReplacesKnownTokensAndKeepsUnknown(): void
    {
        $out = MessageRenderer::render('Rule {rule}: ROI {roi}% on {missing}', ['rule' => 'A', 'roi' => -25]);
        $this->assertSame('Rule A: ROI -25% on {missing}', $out);
    }

    public function testReturnsTemplateUnchangedWhenNoBraces(): void
    {
        $this->assertSame('plain text', MessageRenderer::render('plain text', ['x' => 1]));
    }

    public function testFlattenNestedToDotKeys(): void
    {
        $flat = MessageRenderer::flatten(['metrics' => ['roi' => 5, 'clicks' => 10], 'rule' => 'X']);
        $this->assertSame(['metrics.roi' => 5, 'metrics.clicks' => 10, 'rule' => 'X'], $flat);
    }

    public function testRenderWithFlattenedDotTokens(): void
    {
        $tokens = MessageRenderer::flatten(['metrics' => ['roi' => 7]]);
        $this->assertSame('roi=7', MessageRenderer::render('roi={metrics.roi}', $tokens));
    }
}
