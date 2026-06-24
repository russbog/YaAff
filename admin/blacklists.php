<?php

/**
 * Blacklist feeds page + endpoint (Phase 6). Lets the admin inspect feed status
 * and trigger an on-demand refresh of the offline IP/UA blacklist cache.
 *
 * JSON actions (?action=): status, update. With no action it renders the page.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../bots/BlacklistUpdater.php';

$feedsPath = __DIR__ . '/../bases/blacklists/feeds.json';
$dir = __DIR__ . '/../bases/blacklists';
$feeds = BlacklistUpdater::loadFeeds($feedsPath);
$store = new BlacklistStore($dir);
$action = (string)($_REQUEST['action'] ?? '');

function bl_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function bl_status(array $feeds, BlacklistStore $store): array
{
    $status = [];
    foreach ($feeds as $feed) {
        $name = (string)($feed['name'] ?? '');
        $type = (string)($feed['type'] ?? '');
        $path = $store->cachePath($name, $type);
        $exists = is_file($path);
        $status[] = [
            'name' => $name,
            'type' => $type,
            'tag' => (string)($feed['tag'] ?? ''),
            'url' => (string)($feed['url'] ?? ''),
            'enabled' => ($feed['enabled'] ?? true) !== false,
            'cached' => $exists,
            'entries' => $exists ? max(0, count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])) : 0,
            'updated_at' => $exists ? date('c', (int)filemtime($path)) : null,
        ];
    }
    return $status;
}

if ($action === 'update') {
    $updater = new BlacklistUpdater($store);
    $results = $updater->update($feeds);
    bl_respond(['ok' => true, 'results' => $results, 'feeds' => bl_status($feeds, $store)]);
}
if ($action === 'status') {
    bl_respond(['ok' => true, 'feeds' => bl_status($feeds, $store)]);
}
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php' ?>
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="container-fluid" style="padding-top:20px">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Bot protection — blacklist feeds</h5>
            <button class="btn btn-sm btn-primary" id="bl-update">Update now</button>
        </div>
        <p class="text-muted">Feeds are configured in <code>bases/blacklists/feeds.json</code>. Matching is offline (no per-click network calls). Schedule refresh via cron: <code>php bases/update_blacklists.php</code>.</p>
        <div id="bl-result"></div>
        <div id="bl-table"></div>
    </div>
</div>
<script>
function renderFeeds(feeds) {
    let html = '<table class="table table-sm table-striped"><thead><tr><th>Feed</th><th>Type</th><th>Tag</th><th>Enabled</th><th>Cached</th><th>Entries</th><th>Updated</th></tr></thead><tbody>';
    feeds.forEach(f => {
        html += `<tr><td>${f.name}</td><td>${f.type}</td><td>${f.tag||''}</td><td>${f.enabled?'yes':'no'}</td><td>${f.cached?'yes':'no'}</td><td>${f.entries}</td><td>${f.updated_at?new Date(f.updated_at).toLocaleString():'-'}</td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('bl-table').innerHTML = html;
}
function loadStatus() {
    fetch('blacklists.php?action=status').then(r => r.json()).then(d => renderFeeds(d.feeds || []));
}
document.getElementById('bl-update').addEventListener('click', function() {
    this.disabled = true;
    document.getElementById('bl-result').innerHTML = '<div class="alert alert-info">Updating…</div>';
    fetch('blacklists.php?action=update').then(r => r.json()).then(d => {
        this.disabled = false;
        let ok = (d.results||[]).filter(r => r.ok && !r.skipped).length;
        let fail = (d.results||[]).filter(r => !r.ok && !r.skipped).length;
        document.getElementById('bl-result').innerHTML = `<div class="alert alert-${fail?'warning':'success'}">Updated ${ok} feed(s), ${fail} failed.</div>`;
        renderFeeds(d.feeds || []);
    }).catch(() => { this.disabled = false; });
});
loadStatus();
</script>
</body>
</html>
