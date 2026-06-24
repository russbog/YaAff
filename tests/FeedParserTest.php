<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bots/FeedParser.php';

class FeedParserTest extends TestCase
{
    public function testParseIpKeepsCidrsAndIpsSkipsCommentsAndJunk(): void
    {
        $raw = "# comment\n1.2.3.0/24\n8.8.8.8\n\n; another comment\n10.0.0.1 extra-token\nnot-an-ip\n1.2.3.0/24\n";
        $this->assertSame(['1.2.3.0/24', '8.8.8.8', '10.0.0.1'], FeedParser::parseIp($raw));
    }

    public function testParseIpHandlesIpv6(): void
    {
        $raw = "2001:db8::/32\n::1\n";
        $this->assertSame(['2001:db8::/32', '::1'], FeedParser::parseIp($raw));
    }

    public function testParseIpDeduplicates(): void
    {
        $this->assertSame(['1.1.1.1'], FeedParser::parseIp("1.1.1.1\n1.1.1.1\n1.1.1.1\n"));
    }

    public function testParseUaLowercasesAndDedups(): void
    {
        $raw = "Googlebot\n# a comment\ncurl\nGOOGLEBOT\n\n  AhrefsBot  \n";
        $this->assertSame(['googlebot', 'curl', 'ahrefsbot'], FeedParser::parseUa($raw));
    }

    public function testCleanLineStripsInlineComments(): void
    {
        $this->assertSame('1.2.3.4', FeedParser::cleanLine('1.2.3.4  # inline'));
        $this->assertSame('value', FeedParser::cleanLine('value ; note'));
    }

    public function testLooksLikeIpOrCidr(): void
    {
        $this->assertTrue(FeedParser::looksLikeIpOrCidr('192.168.0.0/16'));
        $this->assertTrue(FeedParser::looksLikeIpOrCidr('8.8.8.8'));
        $this->assertFalse(FeedParser::looksLikeIpOrCidr('example.com'));
        $this->assertFalse(FeedParser::looksLikeIpOrCidr(''));
    }
}
