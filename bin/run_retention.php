<?php

/**
 * Data retention / pruning (cron entry point, Phase 12).
 *
 * Deletes click and audit-log rows older than `retentionDays` (settings.php).
 * A value of 0 disables pruning. Designed to run daily from cron:
 *
 *   17 4 * * * php /path/to/bin/run_retention.php >> /var/log/yatds-retention.log 2>&1
 *
 * The retention window can be overridden on the command line for ad-hoc runs:
 *
 *   php bin/run_retention.php --days=30
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../data/RetentionManager.php';

global $db, $cloSettings;
$db = $db ?? new Db();

$days = (int)($cloSettings['retentionDays'] ?? 0);
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int)$m[1];
    }
}

if ($days <= 0) {
    fwrite(STDOUT, "Retention disabled (retentionDays=0). Nothing to do.\n");
    exit(0);
}

$now = time();
$manager = new RetentionManager($db->driver());
$result = $manager->prune($days, $now);

$total = 0;
foreach ($result['deleted'] as $table => $count) {
    $total += $count;
    fwrite(STDOUT, sprintf("  %-20s %d row(s)\n", $table, $count));
}

fwrite(STDOUT, sprintf(
    "[%s] Pruned data older than %d day(s) (before %s): %d row(s) total.\n",
    date('c', $now),
    $days,
    date('c', $result['cutoff']),
    $total
));
