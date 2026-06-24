<?php

/**
 * Cron entry point: refresh the offline IP/UA blacklist cache from the
 * configured feeds.
 *
 *   php bases/update_blacklists.php [path/to/feeds.json]
 *
 * Add to crontab to keep blacklists current, e.g. hourly:
 *   0 * * * * php /path/to/bases/update_blacklists.php >> /var/log/blacklists.log 2>&1
 */

require_once __DIR__ . '/../bots/BlacklistUpdater.php';

$feedsPath = $argv[1] ?? (__DIR__ . '/blacklists/feeds.json');
$feeds = BlacklistUpdater::loadFeeds($feedsPath);

$store = new BlacklistStore(__DIR__ . '/blacklists');
$updater = new BlacklistUpdater($store);
$results = $updater->update($feeds);

$ts = date('c');
foreach ($results as $r) {
    $status = $r['skipped'] ? 'skip' : ($r['ok'] ? 'ok' : 'FAIL');
    $line = sprintf(
        "[%s] %-6s %-24s %-3s count=%d %s",
        $ts,
        $status,
        $r['name'],
        $r['type'],
        $r['count'],
        $r['error'] !== '' ? ('error=' . $r['error']) : ''
    );
    fwrite(STDOUT, rtrim($line) . "\n");
}
