<?php

/**
 * Pure derivation of report/dashboard KPIs from raw base counters. Kept free of
 * I/O so it is fully unit-testable and reusable by the live dashboard, scheduled
 * exports and the REST API alike.
 */
class ReportAggregator
{
    /**
     * Given base counters, return them merged with derived metrics.
     *
     * Recognized base keys (missing ones default to 0):
     *   clicks, uniques, bots, leads, purchases, rejects, conversions,
     *   revenue, cost
     *
     * Derived: profit, roi, cr (conversion rate %), epc, cpc, uniques_ratio,
     *          bot_ratio.
     *
     * @param array<string,mixed> $base
     * @return array<string,float|int>
     */
    public static function metrics(array $base): array
    {
        $clicks = (float)($base['clicks'] ?? 0);
        $uniques = (float)($base['uniques'] ?? 0);
        $bots = (float)($base['bots'] ?? 0);
        $blocked = (float)($base['blocked'] ?? 0);
        $conversions = (float)($base['conversions'] ?? 0);
        $revenue = (float)($base['revenue'] ?? 0);
        $cost = (float)($base['cost'] ?? 0);

        $profit = $revenue - $cost;
        $totalTraffic = $clicks + $blocked;

        return [
            'clicks' => (int)$clicks,
            'uniques' => (int)$uniques,
            'bots' => (int)$bots,
            'blocked' => (int)($base['blocked'] ?? 0),
            'leads' => (int)($base['leads'] ?? 0),
            'purchases' => (int)($base['purchases'] ?? 0),
            'rejects' => (int)($base['rejects'] ?? 0),
            'conversions' => (int)$conversions,
            'revenue' => self::round2($revenue),
            'cost' => self::round2($cost),
            'profit' => self::round2($profit),
            'roi' => self::pct($profit, $cost),
            'cr' => self::pct($conversions, $clicks),
            'epc' => self::ratio($revenue, $clicks),
            'cpc' => self::ratio($cost, $clicks),
            'uniques_ratio' => self::pct($uniques, $clicks),
            'bot_ratio' => self::pct($bots, $totalTraffic),
        ];
    }

    /** Percentage of $part within $whole, 0 when $whole is 0. */
    public static function pct(float $part, float $whole): float
    {
        return $whole > 0 ? self::round2($part / $whole * 100.0) : 0.0;
    }

    /** Plain ratio $a/$b, 0 when $b is 0. */
    public static function ratio(float $a, float $b): float
    {
        return $b > 0 ? self::round2($a / $b) : 0.0;
    }

    public static function round2(float $v): float
    {
        return round($v, 2);
    }

    /**
     * Merge a clicks-timeseries and a conversions-timeseries (both keyed by a
     * bucket label) into a single ordered series with zero-filled gaps.
     *
     * @param array<string,array<string,mixed>> $clickBuckets bucket => [clicks,uniques,cost]
     * @param array<string,array<string,mixed>> $convBuckets  bucket => [conversions,revenue]
     * @return array<int,array<string,float|int|string>>
     */
    public static function mergeSeries(array $clickBuckets, array $convBuckets): array
    {
        $labels = array_keys($clickBuckets + $convBuckets);
        sort($labels);
        $out = [];
        foreach ($labels as $label) {
            $c = $clickBuckets[$label] ?? [];
            $v = $convBuckets[$label] ?? [];
            $out[] = [
                'bucket' => (string)$label,
                'clicks' => (int)($c['clicks'] ?? 0),
                'uniques' => (int)($c['uniques'] ?? 0),
                'cost' => self::round2((float)($c['cost'] ?? 0)),
                'conversions' => (int)($v['conversions'] ?? 0),
                'revenue' => self::round2((float)($v['revenue'] ?? 0)),
            ];
        }
        return $out;
    }
}
