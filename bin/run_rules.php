<?php

/**
 * Automation rules + scheduler (cron entry point, Phase 8).
 *
 * Runs every enabled rule whose schedule is due, evaluates its metric
 * conditions and executes its actions (pause/resume campaign, weight
 * correction, report export, blacklist refresh, ...). Designed to be invoked
 * once a minute from cron:
 *
 *   * * * * * php /path/to/bin/run_rules.php >> /var/log/yatds-rules.log 2>&1
 *
 * Each run is idempotent: interval/cron schedules and per-rule last_run
 * bookkeeping prevent a rule from firing more often than configured.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../rules/RuleScheduler.php';

global $db;
$db = $db ?? new Db();

$now = time();
$scheduler = new RuleScheduler($db->driver(), new RuleActionExecutor(), $db);
$results = $scheduler->run($now);

$fired = count($results);
$matched = 0;
foreach ($results as $r) {
    if (!empty($r['matched'])) {
        $matched++;
    }
    $line = sprintf(
        "[%s] rule #%d \"%s\" %s",
        date('c', $now),
        (int)($r['rule_id'] ?? 0),
        (string)($r['name'] ?? ''),
        !empty($r['matched']) ? 'matched' : 'no-match'
    );
    foreach (($r['actions'] ?? []) as $a) {
        $line .= sprintf(' | %s:%s %s', $a['type'] ?? '?', !empty($a['ok']) ? 'ok' : 'fail', $a['message'] ?? '');
    }
    fwrite(STDOUT, $line . "\n");
}

fwrite(STDOUT, sprintf("Processed %d due rule(s); %d matched.\n", $fired, $matched));
