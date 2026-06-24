<?php

/**
 * Scheduled / on-demand report export (cron entry point).
 *
 * Usage:
 *   php bin/export_report.php path/to/report.json [output/path]
 *
 * The report config is data-driven JSON, e.g.:
 *   {
 *     "campaign_id": 0,
 *     "timezone": "UTC",
 *     "range": "today",            // today|yesterday|7d|30d|<seconds>
 *     "fields": ["clicks","uniques","conversion","revenue","profit"],
 *     "group_by": ["date","country"],
 *     "format": "csv",             // csv|json
 *     "output": "exports/{name}-{date}.csv"   // optional; {date}/{ts}/{name} tokens
 *   }
 *
 * Delivery (email/webhook) is intentionally out of scope here — Phase 8/9 wire
 * the scheduler and notifications around this generic exporter.
 */

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../reports/ReportExporter.php';

function fail(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath)) {
    fail('Report config not found. Usage: php bin/export_report.php <config.json> [output]');
}

$cfg = json_decode((string)file_get_contents($configPath), true);
if (!is_array($cfg)) {
    fail('Invalid report config JSON: ' . $configPath);
}

$campId = (int)($cfg['campaign_id'] ?? 0);
$timezone = (string)($cfg['timezone'] ?? 'UTC');
$fields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : ['clicks', 'uniques', 'conversion', 'revenue', 'profit'];
$groupBy = is_array($cfg['group_by'] ?? null) ? $cfg['group_by'] : [];
$format = strtolower((string)($cfg['format'] ?? 'csv'));
$name = (string)($cfg['name'] ?? pathinfo($configPath, PATHINFO_FILENAME));

[$start, $end] = resolve_range($cfg['range'] ?? '7d', $timezone);

global $db;
$tree = $db->get_statistics($fields, $groupBy, $campId, (string)$start, (string)$end, $timezone, [], []);
$rows = ReportExporter::flattenTree($tree, $groupBy);

$body = $format === 'json' ? ReportExporter::toJson($rows) : ReportExporter::toCsv($rows);

$out = $argv[2] ?? (string)($cfg['output'] ?? '');
if ($out === '') {
    fwrite(STDOUT, $body);
    exit(0);
}

$out = strtr($out, [
    '{name}' => preg_replace('/[^A-Za-z0-9_\-]/', '_', $name),
    '{date}' => date('Y-m-d'),
    '{ts}'   => (string)time(),
]);
$dir = dirname($out);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail('Cannot create output directory: ' . $dir);
}
if (file_put_contents($out, $body) === false) {
    fail('Cannot write output file: ' . $out);
}
fwrite(STDOUT, sprintf("Exported %d rows to %s\n", count($rows), $out));

/**
 * @return array{0:int,1:int} [startTs, endTs]
 */
function resolve_range(mixed $range, string $timezone): array
{
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $now = new DateTime('now', $tz);

    if (is_numeric($range)) {
        $end = (int)$now->getTimestamp();
        return [$end - (int)$range, $end];
    }

    switch ((string)$range) {
        case 'today':
            $s = (clone $now)->setTime(0, 0, 0);
            return [$s->getTimestamp(), $now->getTimestamp()];
        case 'yesterday':
            $s = (clone $now)->modify('-1 day')->setTime(0, 0, 0);
            $e = (clone $now)->setTime(0, 0, 0);
            return [$s->getTimestamp(), $e->getTimestamp()];
        case '30d':
            return [$now->getTimestamp() - 2592000, $now->getTimestamp()];
        case '7d':
        default:
            return [$now->getTimestamp() - 604800, $now->getTimestamp()];
    }
}
