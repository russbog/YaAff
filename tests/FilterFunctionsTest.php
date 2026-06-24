<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../filters/FilterFunctions.php';

class FilterFunctionsTest extends TestCase
{
    public function testPlainEqualityIsCaseInsensitive(): void
    {
        $this->assertTrue(FilterFunctions::valueMatches('Windows', 'windows'));
        $this->assertFalse(FilterFunctions::valueMatches('Windows', 'linux'));
    }

    public function testWildcardMaskMatching(): void
    {
        $this->assertTrue(FilterFunctions::valueMatches('Chrome 120', 'Chrome*'));
        $this->assertTrue(FilterFunctions::valueMatches('iPhone', 'iPhon?'));
        $this->assertFalse(FilterFunctions::valueMatches('iPhone', 'iPad*'));
    }

    public function testRegexMatching(): void
    {
        $this->assertTrue(FilterFunctions::isRegex('/^ab.*$/i'));
        $this->assertFalse(FilterFunctions::isRegex('abc'));
        $this->assertTrue(FilterFunctions::valueMatches('abcdef', '/^abc/'));
        $this->assertFalse(FilterFunctions::valueMatches('xyz', '/^abc/'));
    }

    public function testValueMatchesAny(): void
    {
        $this->assertTrue(FilterFunctions::valueMatchesAny('US', ['ca', 'us', 'gb']));
        $this->assertTrue(FilterFunctions::valueMatchesAny('Chrome', ['Fire*', 'Chr*']));
        $this->assertFalse(FilterFunctions::valueMatchesAny('US', ['ca', 'gb']));
    }

    public function testSearchEngineDetection(): void
    {
        $table = [
            ['name' => 'Google', 'hosts' => ['google.'], 'keyword_params' => ['q']],
            ['name' => 'Yandex', 'hosts' => ['yandex.'], 'keyword_params' => ['text']],
        ];
        $this->assertSame('Google', FilterFunctions::detectSearchEngine('https://www.google.com/search?q=hi', $table));
        $this->assertSame('Yandex', FilterFunctions::detectSearchEngine('https://yandex.ru/search/?text=hi', $table));
        $this->assertSame('', FilterFunctions::detectSearchEngine('https://example.com/', $table));
        $this->assertSame('', FilterFunctions::detectSearchEngine('', $table));
    }

    public function testKeywordExtraction(): void
    {
        $table = [
            ['name' => 'Google', 'hosts' => ['google.'], 'keyword_params' => ['q']],
            ['name' => 'Yandex', 'hosts' => ['yandex.'], 'keyword_params' => ['text']],
        ];
        $this->assertSame('hello world', FilterFunctions::extractKeyword('https://google.com/search?q=hello+world', $table));
        $this->assertSame('foo', FilterFunctions::extractKeyword('https://yandex.ru/?text=foo', $table));
        $this->assertSame('', FilterFunctions::extractKeyword('https://google.com/', $table));
    }

    public function testTimetableMatching(): void
    {
        // Monday 2021-01-04 10:00:00 UTC
        $monday10 = gmmktime(10, 0, 0, 1, 4, 2021);
        $rules = [['days' => [1, 2, 3, 4, 5], 'from' => 9, 'to' => 18]];
        $this->assertTrue(FilterFunctions::timetableMatches($rules, $monday10, 'UTC'));

        $monday20 = gmmktime(20, 0, 0, 1, 4, 2021);
        $this->assertFalse(FilterFunctions::timetableMatches($rules, $monday20, 'UTC'));

        // Sunday 2021-01-03
        $sunday10 = gmmktime(10, 0, 0, 1, 3, 2021);
        $this->assertFalse(FilterFunctions::timetableMatches($rules, $sunday10, 'UTC'));

        $this->assertTrue(FilterFunctions::timetableMatches([], $monday20, 'UTC'));
    }

    public function testTimezoneShiftsWeekdayAndHour(): void
    {
        // 2021-01-04 01:00 UTC == 2021-01-03 20:00 in America/New_York (Sunday)
        $ts = gmmktime(1, 0, 0, 1, 4, 2021);
        $weekdayRule = [['days' => [1], 'from' => 0, 'to' => 24]]; // Monday only
        $this->assertTrue(FilterFunctions::timetableMatches($weekdayRule, $ts, 'UTC'));
        $this->assertFalse(FilterFunctions::timetableMatches($weekdayRule, $ts, 'America/New_York'));
    }

    public function testDateBetween(): void
    {
        $this->assertTrue(FilterFunctions::dateBetween(150, 100, 200));
        $this->assertFalse(FilterFunctions::dateBetween(50, 100, 200));
        $this->assertFalse(FilterFunctions::dateBetween(250, 100, 200));
        $this->assertTrue(FilterFunctions::dateBetween(250, 100, 0)); // open upper bound
        $this->assertTrue(FilterFunctions::dateBetween(50, 0, 200));  // open lower bound
    }

    public function testNumericCompare(): void
    {
        $this->assertTrue(FilterFunctions::numericCompare(5, 'greater_than', 3));
        $this->assertFalse(FilterFunctions::numericCompare(3, 'greater_than', 5));
        $this->assertTrue(FilterFunctions::numericCompare(3, 'less_than', 5));
        $this->assertTrue(FilterFunctions::numericCompare(5, 'greater_or_equal', 5));
    }
}
