<?php

/**
 * CLI cron monitor: re-checks every pool domain's health and auto-fixes the
 * fixable ones. Intended to run as root from system cron (so the Let's Encrypt
 * path can issue/renew certificates and reload nginx):
 *
 *   * /5 * * * * php /var/www/yaaff/admin/domainmonitor.php >> /var/log/yaaff-domains.log 2>&1
 *
 * For each domain it stores the live status, then — only when a fix is both
 * needed and possible — remediates: Cloudflare-fronted domains via the CF API,
 * direct domains via Let's Encrypt. DNS-level problems are the operator's to
 * fix (point the A record here), so they are reported but never "fixed".
 *
 * No HTTP/session involved; never throws — failures are logged and skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../bases/ipcountry.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../domains/DomainStatusChecker.php';
require_once __DIR__ . '/../domains/DomainStatusStore.php';
require_once __DIR__ . '/../domains/DomainFixer.php';
require_once __DIR__ . '/../domains/ServerIp.php';

$ts = static fn(): string => '[' . gmdate('Y-m-d H:i:s') . ' UTC]';

global $db;
$driver = $db->driver();
$repo = Repositories::domains($driver);
$store = new DomainStatusStore();
$checker = new DomainStatusChecker();
$fixer = new DomainFixer();
$serverIp = ServerIp::detect();

// Statuses where remediation makes sense (DNS issues are the operator's job).
$fixable = [DomainStatusChecker::SSL_AWAIT, DomainStatusChecker::SSL_ERROR];

$domains = $repo->findAll();
echo $ts() . ' monitor start: ' . count($domains) . " domain(s), serverIp={$serverIp}\n";

foreach ($domains as $domain) {
    /** @var Domain $domain */
    $id = (int)$domain->id;
    $record = $checker->check($domain, $serverIp);
    $store->put($id, $record);
    echo $ts() . " {$record['host']}: {$record['status']} — {$record['detail']}\n";

    $shouldFix = $record['needs_fix'] === true || in_array($record['status'], $fixable, true);
    if (!$shouldFix) {
        continue;
    }

    $fix = $fixer->fix($domain, true); // root → may issue Let's Encrypt
    echo $ts() . "   fix[{$fix['method']}]: " . ($fix['ok'] ? 'ok' : 'FAILED') . ' — ' . $fix['detail'] . "\n";

    // Re-check so the stored status reflects the post-fix state.
    $after = $checker->check($domain, $serverIp);
    $store->put($id, $after);
    echo $ts() . "   recheck: {$after['status']}\n";
}

echo $ts() . " monitor done\n";
