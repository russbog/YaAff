<?php

/**
 * Pure, side-effect-free helpers used by the filtration core.
 *
 * Everything here is data-driven and unit-testable in isolation: value
 * comparison (incl. wildcard masks and regex), search-engine detection from
 * referer, timetable/day-of-week matching and numeric range checks. No request
 * globals, DB or GeoIP access happens in this file.
 */
class FilterFunctions
{
    /** Wildcard mask characters Keitaro-style: * (any run), ? (single char). */
    public static function hasWildcard(string $pattern): bool
    {
        return str_contains($pattern, '*') || str_contains($pattern, '?');
    }

    /** A value wrapped in slashes is treated as a PCRE pattern, e.g. /^abc.*$/i */
    public static function isRegex(string $pattern): bool
    {
        $len = strlen($pattern);
        if ($len < 2 || $pattern[0] !== '/') {
            return false;
        }
        $last = strrpos($pattern, '/');
        return $last !== false && $last > 0;
    }

    public static function wildcardToRegex(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '*') {
                $out .= '.*';
            } elseif ($ch === '?') {
                $out .= '.';
            } else {
                $out .= preg_quote($ch, '/');
            }
        }
        return '/^' . $out . '$/i';
    }

    /**
     * Single-value match honoring regex (/.../), wildcard masks (*, ?) and
     * plain case-insensitive equality (fallback).
     */
    public static function valueMatches(string $value, string $pattern): bool
    {
        if (self::isRegex($pattern)) {
            $res = @preg_match($pattern, $value);
            return $res === 1;
        }
        if (self::hasWildcard($pattern)) {
            return preg_match(self::wildcardToRegex($pattern), $value) === 1;
        }
        return strcasecmp($value, $pattern) === 0;
    }

    /** True if $value matches ANY of the comma-separated patterns. */
    public static function valueMatchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            if (self::valueMatches($value, $p)) {
                return true;
            }
        }
        return false;
    }

    public static function splitValues(string $val): array
    {
        return array_map('trim', explode(',', $val));
    }

    /**
     * Detect a search engine name from a referer using a data-driven pattern
     * table ([{name, hosts:[...], keyword_params:[...]}, ...]).
     * Returns '' when no engine matched.
     */
    public static function detectSearchEngine(string $referer, array $table): string
    {
        if ($referer === '') {
            return '';
        }
        $host = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return '';
        }
        foreach ($table as $engine) {
            foreach (($engine['hosts'] ?? []) as $needle) {
                $needle = strtolower((string)$needle);
                if ($needle !== '' && str_contains($host, $needle)) {
                    return (string)($engine['name'] ?? $needle);
                }
            }
        }
        return '';
    }

    /** Extract the search keyword from a referer given the engine pattern table. */
    public static function extractKeyword(string $referer, array $table): string
    {
        if ($referer === '') {
            return '';
        }
        $qs = (string)(parse_url($referer, PHP_URL_QUERY) ?? '');
        if ($qs === '') {
            return '';
        }
        parse_str($qs, $params);
        $host = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));

        $candidates = ['q', 'query', 'text', 'wd', 'p', 'search_query'];
        foreach ($table as $engine) {
            $matched = false;
            foreach (($engine['hosts'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($host, strtolower((string)$needle))) {
                    $matched = true;
                    break;
                }
            }
            if ($matched && !empty($engine['keyword_params'])) {
                $candidates = array_merge($engine['keyword_params'], $candidates);
                break;
            }
        }
        foreach ($candidates as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return (string)$params[$key];
            }
        }
        return '';
    }

    /**
     * Timetable match. Rules is a list of intervals; each interval has a set of
     * weekdays (1=Mon..7=Sun) and an hour range [from,to) in the campaign tz.
     * Example: [{"days":[1,2,3,4,5],"from":9,"to":18}]
     * Empty rules => always matches.
     */
    public static function timetableMatches(array $rules, int $timestamp, string $tz = 'UTC'): bool
    {
        if (empty($rules)) {
            return true;
        }
        try {
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'UTC'));
        } catch (Throwable $e) {
            $dt = new DateTime('@' . $timestamp);
        }
        $weekday = (int)$dt->format('N'); // 1..7
        $hour = (int)$dt->format('G');    // 0..23

        foreach ($rules as $rule) {
            $days = $rule['days'] ?? [];
            if (!empty($days) && !in_array($weekday, array_map('intval', $days), true)) {
                continue;
            }
            $from = isset($rule['from']) ? (int)$rule['from'] : 0;
            $to = isset($rule['to']) ? (int)$rule['to'] : 24;
            if ($hour >= $from && $hour < $to) {
                return true;
            }
        }
        return false;
    }

    /** Inclusive date-range check; bounds are unix timestamps, 0 means open. */
    public static function dateBetween(int $timestamp, int $from, int $to): bool
    {
        if ($from > 0 && $timestamp < $from) {
            return false;
        }
        if ($to > 0 && $timestamp > $to) {
            return false;
        }
        return true;
    }

    /** Generic numeric comparison used by extended operators. */
    public static function numericCompare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            'greater_than' => $left > $right,
            'less_than' => $left < $right,
            'greater_or_equal' => $left >= $right,
            'less_or_equal' => $left <= $right,
            'equal' => $left === $right,
            'not_equal' => $left !== $right,
            default => false,
        };
    }
}
