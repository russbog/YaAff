<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tokens/TokenRegistry.php';

class TokenRegistryTest extends TestCase
{
    public function testResolveClickidAndUserid(): void
    {
        $r = new TokenRegistry('CLK1', 'USR1');
        $this->assertSame('CLK1', $r->resolve('clickid'));
        $this->assertSame('USR1', $r->resolve('userid'));
    }

    public function testResolveUnknownReturnsNull(): void
    {
        $r = new TokenRegistry('CLK1');
        $this->assertNull($r->resolve('nope'));
    }

    public function testResolveClickColumn(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'country' => 'US', 'ua' => 'Moz']);
        $this->assertSame('US', $r->resolve('country'));
        $this->assertSame('Moz', $r->resolve('ua'));
    }

    public function testResolveCustomParamFromJson(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => json_encode(['fbc' => 'fb.1'])]);
        $this->assertSame('fb.1', $r->resolve('c.fbc'));
    }

    public function testResolveCustomParamFromArray(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['k' => 'v']]);
        $this->assertSame('v', $r->resolve('c.k'));
    }

    public function testResolveSubTokenFromParams(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['sub1' => 'a', 'sub_id_2' => 'b']]);
        $this->assertSame('a', $r->resolve('sub1'));
        $this->assertSame('b', $r->resolve('sub_id_2'));
    }

    public function testOverridesWinOverClick(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'status' => 'New'], ['status' => 'Purchase']);
        $this->assertSame('Purchase', $r->resolve('status'));
    }

    public function testResolveTimeIsNumericString(): void
    {
        $r = new TokenRegistry('C');
        $this->assertMatchesRegularExpression('/^\d+$/', (string)$r->resolve('time'));
    }

    public function testResolveHashOfClickid(): void
    {
        $r = new TokenRegistry('CLK1');
        $this->assertSame(md5('CLK1'), $r->resolve('hash:clickid'));
    }

    public function testResolveRandomInRange(): void
    {
        $r = new TokenRegistry('C');
        $val = (int)$r->resolve('random:5-5');
        $this->assertSame(5, $val);
    }

    public function testResolveRandomInvalidReturnsNull(): void
    {
        $r = new TokenRegistry('C');
        $this->assertNull($r->resolve('random:abc'));
    }

    public function testRenderInlineSubstitution(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'country' => 'US']);
        $this->assertSame('id=C geo=US', $r->render('id={clickid} geo={country}'));
    }

    public function testRenderLeavesUnknownIntact(): void
    {
        $r = new TokenRegistry('C');
        $this->assertSame('a {unknown} b', $r->render('a {unknown} b'));
    }

    public function testRenderEmptyAndNoBraceShortCircuit(): void
    {
        $r = new TokenRegistry('C');
        $this->assertSame('', $r->render(''));
        $this->assertSame('literal', $r->render('literal'));
    }

    public function testToArrayFlattensClickAndParams(): void
    {
        $r = TokenRegistry::fromClick([
            'clickid' => 'C',
            'country' => 'US',
            'params' => json_encode(['fbc' => 'x']),
            'path' => '/p',
        ], ['status' => 'Lead']);
        $tokens = $r->toArray();
        $this->assertSame('C', $tokens['clickid']);
        $this->assertSame('US', $tokens['country']);
        $this->assertSame('x', $tokens['c.fbc']);
        $this->assertSame('Lead', $tokens['status']);
        $this->assertArrayNotHasKey('path', $tokens);
    }

    public function testWithOverridesMergesNewWinning(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C'], ['status' => 'New']);
        $r2 = $r->withOverrides(['status' => 'Purchase', 'payout' => 9]);
        $this->assertSame('Purchase', $r2->resolve('status'));
        $this->assertSame('9', $r2->resolve('payout'));
        $this->assertSame('New', $r->resolve('status'));
    }

    public function testLazyLoaderResolvesMissingColumn(): void
    {
        $loaded = false;
        $r = new TokenRegistry('C', null, [], [], function () use (&$loaded): array {
            $loaded = true;
            return ['country' => 'DE', 'params' => ['sub1' => 'z']];
        });
        $this->assertSame('DE', $r->resolve('country'));
        $this->assertSame('z', $r->resolve('sub1'));
        $this->assertTrue($loaded);
    }

    public function testResolveBareCustomParamFallback(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['random_name' => 'rs']]);
        $this->assertSame('rs', $r->resolve('random_name'));
        $this->assertSame('rs', $r->resolve('c.random_name'));
        $this->assertNull($r->resolve('not_a_param'));
    }

    public function testRenderUrlSubstitutesPathAndQuery(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['random_name' => 'random_string', 'parametr2' => 'this']]);
        $this->assertSame(
            'offer.com/random_string?to=this',
            $r->renderUrl('offer.com/{random_name}?to={parametr2}')
        );
        $this->assertSame(
            'https://offer.com/random_string?to=this',
            $r->renderUrl('https://offer.com/{random_name}?to={parametr2}')
        );
    }

    public function testRenderUrlMissingTokenCollapsesToEmpty(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['parametr2' => 'this']]);
        $this->assertSame(
            'offer.com/?to=this',
            $r->renderUrl('offer.com/{random_name}?to={parametr2}')
        );
    }

    public function testRenderUrlEncodesResolvedValues(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['p' => 'a b&c']]);
        $this->assertSame('https://o.com/a%20b%26c', $r->renderUrl('https://o.com/{p}'));
        $this->assertSame('https://o.com/?x=a+b%26c', $r->renderUrl('https://o.com/?x={p}'));
    }

    public function testRenderUrlSubstitutesEmbeddedTokenInValue(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C', 'params' => ['sid' => '42']]);
        $this->assertSame('https://o.com/?u=pre42post', $r->renderUrl('https://o.com/?u=pre{sid}post'));
    }

    public function testRenderUrlWithoutTokensUnchanged(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'C']);
        $this->assertSame('https://o.com/x?a=b', $r->renderUrl('https://o.com/x?a=b'));
    }

    public function testRenderUrlResolvesKnownTokens(): void
    {
        $r = TokenRegistry::fromClick(['clickid' => 'CLK', 'country' => 'US']);
        $this->assertSame(
            'https://o.com/US?cid=CLK',
            $r->renderUrl('https://o.com/{country}?cid={clickid}')
        );
    }
}
