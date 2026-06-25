<?php

use PHPUnit\Framework\TestCase;

$GLOBALS['cloSettings'] = ['debug' => false];
if (!defined('YELLOWTDS_NO_DB_BOOTSTRAP')) {
    define('YELLOWTDS_NO_DB_BOOTSTRAP', true);
}

require_once __DIR__ . '/../debug.php';
require_once __DIR__ . '/../core.php';

/**
 * Exercises the extended filter operators and the new filter types through
 * FiltrationCore without booting DeviceDetector/MaxMind: the constructor is
 * bypassed and click_params injected directly.
 */
class ExtendedFilterTest extends TestCase
{
    private function core(array $clickParams): FiltrationCore
    {
        $ref = new ReflectionClass(FiltrationCore::class);
        /** @var FiltrationCore $c */
        $c = $ref->newInstanceWithoutConstructor();
        $c->click_params = $clickParams + ['qs' => []];
        return $c;
    }

    private function filters(string $condition, array $rules): array
    {
        return ['condition' => $condition, 'rules' => $rules];
    }

    public function testDirectParamInMatch(): void
    {
        $c = $this->core(['country' => 'US']);
        $f = $this->filters('AND', [['id' => 'country', 'operator' => 'in', 'value' => 'us,ca']]);
        $this->assertTrue($c->click_matches_filters($f));

        $f = $this->filters('AND', [['id' => 'country', 'operator' => 'in', 'value' => 'gb,ca']]);
        $this->assertFalse($c->click_matches_filters($f));
    }

    public function testRegionAndCityFilters(): void
    {
        $c = $this->core(['region' => 'California', 'city' => 'Los Angeles']);
        $f = $this->filters('AND', [
            ['id' => 'region', 'operator' => 'in', 'value' => 'California'],
            ['id' => 'city', 'operator' => 'contains', 'value' => 'Angeles'],
        ]);
        $this->assertTrue($c->click_matches_filters($f));
    }

    public function testConnectionTypeAndSearchEngine(): void
    {
        $c = $this->core(['connection_type' => 'cellular', 'search_engine' => 'Google']);
        $f = $this->filters('AND', [
            ['id' => 'connection_type', 'operator' => 'in', 'value' => 'cellular'],
            ['id' => 'search_engine', 'operator' => 'in', 'value' => 'Google,Bing'],
        ]);
        $this->assertTrue($c->click_matches_filters($f));
    }

    public function testUaAlias(): void
    {
        $c = $this->core(['ua' => 'Mozilla/5.0 SuperBot/1.0']);
        $f = $this->filters('AND', [['id' => 'ua', 'operator' => 'contains', 'value' => 'SuperBot']]);
        $this->assertTrue($c->click_matches_filters($f));
        $f = $this->filters('AND', [['id' => 'useragent', 'operator' => 'contains', 'value' => 'SuperBot']]);
        $this->assertTrue($c->click_matches_filters($f));
    }

    public function testSiteDerivedFromReferer(): void
    {
        $c = $this->core(['referer' => 'https://news.example.com/a/b?x=1']);
        $f = $this->filters('AND', [['id' => 'site', 'operator' => 'equal', 'value' => 'news.example.com']]);
        $this->assertTrue($c->click_matches_filters($f));
    }

    public function testWildcardAndRegexOperators(): void
    {
        $c = $this->core(['client' => 'Chrome Mobile']);
        $f = $this->filters('AND', [['id' => 'client', 'operator' => 'in', 'value' => 'Chrome*']]);
        $this->assertTrue($c->click_matches_filters($f));

        $f = $this->filters('AND', [['id' => 'client', 'operator' => 'matches', 'value' => '^Chrome']]);
        $this->assertTrue($c->click_matches_filters($f));

        $f = $this->filters('AND', [['id' => 'client', 'operator' => 'regex', 'value' => '/firefox/i']]);
        $this->assertFalse($c->click_matches_filters($f));
    }

    public function testQueryStringFallbackForCampaignParams(): void
    {
        $c = $this->core(['qs' => ['sub_id_1' => 'fb_campaign', 'creative_id' => '777']]);
        $f = $this->filters('AND', [
            ['id' => 'sub_id_1', 'operator' => 'in', 'value' => 'fb_campaign'],
            ['id' => 'creative_id', 'operator' => 'in', 'value' => '777'],
        ]);
        $this->assertTrue($c->click_matches_filters($f));

        $f = $this->filters('AND', [['id' => 'creative_id', 'operator' => 'in', 'value' => '999']]);
        $this->assertFalse($c->click_matches_filters($f));
    }

    public function testSubIdAndUtmFiltersResolveFromQueryString(): void
    {
        // Every sub_id_1..30 / utm_* filter id newly exposed in the UI must
        // resolve through the generic query-string fallback.
        $c = $this->core(['qs' => [
            'sub_id_5' => 'adset_42',
            'sub_id_30' => 'last',
            'utm_source' => 'facebook',
            'utm_campaign' => 'summer_sale',
        ]]);
        $f = $this->filters('AND', [
            ['id' => 'sub_id_5', 'operator' => 'in', 'value' => 'adset_42'],
            ['id' => 'sub_id_30', 'operator' => 'equal', 'value' => 'last'],
            ['id' => 'utm_source', 'operator' => 'in', 'value' => 'facebook,google'],
            ['id' => 'utm_campaign', 'operator' => 'contains', 'value' => 'sale'],
        ]);
        $this->assertTrue($c->click_matches_filters($f));

        $miss = $this->filters('AND', [['id' => 'sub_id_5', 'operator' => 'not_in', 'value' => 'adset_42']]);
        $this->assertFalse($c->click_matches_filters($miss));
    }

    public function testBotFilter(): void
    {
        $bot = $this->core(['bot' => 1]);
        $human = $this->core(['bot' => 0]);
        $wantHuman = $this->filters('AND', [['id' => 'bot', 'operator' => 'in', 'value' => 1]]);
        $wantBot = $this->filters('AND', [['id' => 'bot', 'operator' => 'in', 'value' => 0]]);
        $this->assertTrue($human->click_matches_filters($wantHuman));
        $this->assertFalse($bot->click_matches_filters($wantHuman));
        $this->assertTrue($bot->click_matches_filters($wantBot));
    }

    public function testTimetableFilter(): void
    {
        $monday10 = gmmktime(10, 0, 0, 1, 4, 2021);
        $c = $this->core(['ts' => $monday10]);
        $rule = [['days' => [1, 2, 3, 4, 5], 'from' => 9, 'to' => 18]];
        $f = $this->filters('AND', [['id' => 'timetable', 'operator' => 'in', 'value' => $rule, 'tz' => 'UTC']]);
        $this->assertTrue($c->click_matches_filters($f));

        $monday20 = gmmktime(20, 0, 0, 1, 4, 2021);
        $c2 = $this->core(['ts' => $monday20]);
        $this->assertFalse($c2->click_matches_filters($f));
    }

    public function testDateBetweenFilter(): void
    {
        $c = $this->core(['ts' => 1500]);
        $f = $this->filters('AND', [['id' => 'date_between', 'operator' => 'in', 'value' => ['from' => 1000, 'to' => 2000]]]);
        $this->assertTrue($c->click_matches_filters($f));

        $f = $this->filters('AND', [['id' => 'date_between', 'operator' => 'in', 'value' => ['from' => 1600, 'to' => 2000]]]);
        $this->assertFalse($c->click_matches_filters($f));
    }

    public function testClickLimitAndUniquenessNonBlockingWithoutDb(): void
    {
        $c = $this->core(['ts' => time()]);
        $cl = $this->filters('AND', [['id' => 'click_limit', 'operator' => 'in', 'value' => ['window' => 'day', 'limit' => 5]]]);
        $this->assertTrue($c->click_matches_filters($cl));

        $uq = $this->filters('AND', [['id' => 'uniqueness', 'operator' => 'in', 'value' => 'unique']]);
        $this->assertTrue($c->click_matches_filters($uq));
    }
}
