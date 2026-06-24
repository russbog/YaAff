<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../domains/DomainMatcher.php';

class DomainMatcherTest extends TestCase
{
    public function testExactMatchCaseAndDotInsensitive(): void
    {
        $this->assertTrue(DomainMatcher::matches(['Example.com'], 'example.com'));
        $this->assertTrue(DomainMatcher::matches(['example.com'], 'example.com.'));
        $this->assertFalse(DomainMatcher::matches(['example.com'], 'other.com'));
    }

    public function testWildcardMatchesSubdomainsOnly(): void
    {
        $this->assertTrue(DomainMatcher::matches(['*.example.com'], 'go.example.com'));
        $this->assertTrue(DomainMatcher::matches(['*.example.com'], 'a.b.example.com'));
        $this->assertFalse(DomainMatcher::matches(['*.example.com'], 'example.org'));
    }

    public function testWildcardDoesNotMatchDifferentTld(): void
    {
        $this->assertFalse(DomainMatcher::matches(['*.example.com'], 'go.example.net'));
    }

    public function testEmptyEntriesIgnored(): void
    {
        $this->assertFalse(DomainMatcher::matches(['', '  '], 'example.com'));
    }

    public function testBuildAliasMapFromJsonAndArraySettings(): void
    {
        $rows = [
            ['name' => 'Alias1.com', 'settings' => json_encode(['type' => 'alias', 'alias_of' => 'Canonical.com'])],
            ['name' => 'alias2.com', 'settings' => ['type' => 'alias', 'alias_of' => 'canonical.com']],
            ['name' => 'regular.com', 'settings' => ['type' => 'regular']],
        ];
        $map = DomainMatcher::buildAliasMap($rows);
        $this->assertSame('canonical.com', $map['alias1.com']);
        $this->assertSame('canonical.com', $map['alias2.com']);
        $this->assertArrayNotHasKey('regular.com', $map);
    }

    public function testBuildAliasMapSkipsIncomplete(): void
    {
        $rows = [
            ['name' => 'a.com', 'settings' => ['type' => 'alias', 'alias_of' => '']],
            ['name' => '', 'settings' => ['type' => 'alias', 'alias_of' => 'b.com']],
        ];
        $this->assertSame([], DomainMatcher::buildAliasMap($rows));
    }

    public function testResolveAliasFollowsChainAndStopsOnCycle(): void
    {
        $map = ['a.com' => 'b.com', 'b.com' => 'c.com'];
        $this->assertSame('c.com', DomainMatcher::resolveAlias($map, 'a.com'));
        $this->assertSame('x.com', DomainMatcher::resolveAlias($map, 'x.com'));

        $cycle = ['a.com' => 'b.com', 'b.com' => 'a.com'];
        $this->assertSame('a.com', DomainMatcher::resolveAlias($cycle, 'a.com'));
    }
}
