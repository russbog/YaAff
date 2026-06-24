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
require_once __DIR__ . '/../notifications/Notifier.php';

global $db;
$db = $db ?? new Db();

$now = time();

// Phase 9 wiring: let rules dispatch alerts via the notify action without
// coupling the Phase 8 action executor to the notifications module.
$executor = new RuleActionExecutor();
$notifier = new Notifier($db->driver());
$executor->register('notify', static function (array $action, array $ctx) use ($notifier): array {
    $rule = $ctx['rule'] ?? null;
    $metrics = is_array($ctx['metrics'] ?? null) ? $ctx['metrics'] : [];

    $tokens = ['event' => (string)($action['event'] ?? 'rule'), 'time' => date('c', time())];
    if ($rule instanceof Rule) {
        $tokens['rule'] = $rule->name ?? '';
        $tokens['campaign'] = $rule->campaignId();
    }
    foreach ($metrics as $k => $v) {
        if (is_scalar($v)) {
            $tokens[(string)$k] = $v;
        }
    }
    if (isset($action['message'])) {
        $tokens['message'] = MessageRenderer::render((string)$action['message'], $tokens);
    }

    $only = isset($action['channels']) ? array_map('intval', (array)$action['channels']) : null;
    $results = $notifier->notify($tokens['event'], $tokens, $only);
    $sent = 0;
    foreach ($results as $r) {
        if (!empty($r['ok'])) {
            $sent++;
        }
    }
    return ['type' => 'notify', 'ok' => true, 'message' => sprintf('notified %d/%d channel(s)', $sent, count($results))];
});

$scheduler = new RuleScheduler($db->driver(), $executor, $db);
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
