<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/ReportAggregator.php';

/**
 * Real-time dashboard / report queries, decoupled from the large Db class so
 * they can run against any {@see DbDriver} (SQLite today, MySQL later) and be
 * unit-tested with a seeded driver. campId = 0 means "all campaigns".
 */
class DashboardQuery
{
    /** Click columns that may be used as a breakdown dimension. */
    public const TOP_FIELDS = [
        'country', 'lang', 'os', 'device', 'brand', 'model',
        'isp', 'client', 'flow', 'path',
    ];

    private DbDriver $driver;

    public function __construct(DbDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Headline KPIs for the window. Combines clicks (volume/cost/bots) with
     * conversions (status counts/revenue), then derives metrics.
     *
     * @return array<string,float|int>
     */
    public function summary(int $campId, int $start, int $end): array
    {
        [$campWhere, $campBind] = $this->campFilter($campId, 'c');
        $clickRow = $this->driver->selectOne(
            "SELECT COUNT(*) AS clicks,
                    COUNT(DISTINCT userid) AS uniques,
                    COALESCE(SUM(cost), 0) AS cost
             FROM clicks c
             WHERE time BETWEEN :start AND :end" . $campWhere,
            $this->binds($start, $end, $campBind)
        ) ?: [];

        [$blkWhere, $blkBind] = $this->campFilter($campId, '');
        $blockedRow = $this->driver->selectOne(
            "SELECT COUNT(*) AS blocked,
                    COALESCE(SUM(CASE WHEN reason = 'bot' THEN 1 ELSE 0 END), 0) AS bots
             FROM blocked
             WHERE time BETWEEN :start AND :end" . $blkWhere,
            $this->binds($start, $end, $blkBind)
        ) ?: [];

        [$convWhere, $convBind] = $this->campFilter($campId, '');
        $convRow = $this->driver->selectOne(
            "SELECT COUNT(*) AS conversions,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN status = 'Lead' THEN 1 ELSE 0 END), 0) AS leads,
                    COALESCE(SUM(CASE WHEN status = 'Purchase' THEN 1 ELSE 0 END), 0) AS purchases,
                    COALESCE(SUM(CASE WHEN status = 'Reject' THEN 1 ELSE 0 END), 0) AS rejects
             FROM conversions
             WHERE time BETWEEN :start AND :end" . $convWhere,
            $this->binds($start, $end, $convBind)
        ) ?: [];

        return ReportAggregator::metrics(array_merge($clickRow, $blockedRow, $convRow));
    }

    /**
     * Time-bucketed series (clicks/uniques/cost from clicks, conversions/revenue
     * from conversions), zero-filled and ordered by bucket.
     *
     * @return array<int,array<string,float|int|string>>
     */
    public function timeseries(int $campId, int $start, int $end, string $tzOffset): array
    {
        $bucketExpr = $this->driver->dateGroup('time', $tzOffset);

        [$campWhere, $campBind] = $this->campFilter($campId, 'c');
        $clickRows = $this->driver->select(
            "SELECT $bucketExpr AS bucket,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT userid) AS uniques,
                    COALESCE(SUM(cost), 0) AS cost
             FROM clicks c
             WHERE time BETWEEN :start AND :end" . $campWhere . "
             GROUP BY bucket",
            $this->binds($start, $end, $campBind)
        );

        [$convWhere, $convBind] = $this->campFilter($campId, '');
        $convRows = $this->driver->select(
            "SELECT $bucketExpr AS bucket,
                    COUNT(*) AS conversions,
                    COALESCE(SUM(revenue), 0) AS revenue
             FROM conversions
             WHERE time BETWEEN :start AND :end" . $convWhere . "
             GROUP BY bucket",
            $this->binds($start, $end, $convBind)
        );

        return ReportAggregator::mergeSeries(
            $this->keyBy($clickRows, 'bucket'),
            $this->keyBy($convRows, 'bucket')
        );
    }

    /**
     * Top breakdown rows for a click dimension (e.g. country, isp, flow).
     *
     * @return array<int,array<string,float|int|string>>
     */
    public function topBy(string $field, int $campId, int $start, int $end, int $limit = 10): array
    {
        if (!in_array($field, self::TOP_FIELDS, true)) {
            return [];
        }
        [$campWhere, $campBind] = $this->campFilter($campId, 'c');
        $limit = max(1, min(100, $limit));
        $rows = $this->driver->select(
            "SELECT COALESCE($field, 'unknown') AS name,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT userid) AS uniques
             FROM clicks c
             WHERE time BETWEEN :start AND :end" . $campWhere . "
             GROUP BY name ORDER BY clicks DESC LIMIT " . $limit,
            $this->binds($start, $end, $campBind)
        );
        return array_map(static function (array $r): array {
            return [
                'name' => (string)($r['name'] ?? 'unknown'),
                'clicks' => (int)($r['clicks'] ?? 0),
                'uniques' => (int)($r['uniques'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return array{0:string,1:array<string,array<int,mixed>>}
     */
    private function campFilter(int $campId, string $alias): array
    {
        if ($campId <= 0) {
            return ['', []];
        }
        $col = $alias !== '' ? ($alias . '.campaign_id') : 'campaign_id';
        return [" AND $col = :campid", [':campid' => [$campId, DbDriver::INT]]];
    }

    /**
     * @param array<string,array<int,mixed>> $extra
     * @return array<string,array<int,mixed>>
     */
    private function binds(int $start, int $end, array $extra): array
    {
        return array_merge([
            ':start' => [$start, DbDriver::INT],
            ':end' => [$end, DbDriver::INT],
        ], $extra);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function keyBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string)($row[$key] ?? '')] = $row;
        }
        return $out;
    }
}
