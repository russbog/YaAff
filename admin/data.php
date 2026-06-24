<?php

/**
 * Backup / restore / retention page + endpoint (Phase 12).
 *
 * JSON actions (?action=):
 *   info     table list with row counts + driver + retention setting (data.view)
 *   backup   download a JSON archive of all tables                  (data.manage)
 *   restore  replace row data from an uploaded archive              (data.manage)
 *   prune    delete rows older than N days                          (data.manage)
 * With no action it renders the page.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../data/BackupManager.php';
require_once __DIR__ . '/../data/RetentionManager.php';
require_once __DIR__ . '/../auth/Auth.php';

global $db, $cloSettings;
$driver = $db->driver();
$action = (string)($_REQUEST['action'] ?? '');

function data_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'info') {
    auth_require('data.view', true);
    $tables = [];
    foreach ($driver->tables() as $t) {
        $row = $driver->selectOne("SELECT COUNT(*) AS c FROM $t");
        $tables[] = ['name' => $t, 'rows' => (int)($row['c'] ?? 0)];
    }
    data_respond([
        'ok' => true,
        'driver' => $driver->name(),
        'retentionDays' => (int)($cloSettings['retentionDays'] ?? 0),
        'retentionTables' => array_keys(RetentionManager::TABLES),
        'tables' => $tables,
    ]);
}

if ($action === 'backup') {
    auth_require('data.manage', true);
    $manager = new BackupManager($driver);
    $filename = 'yaaff-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $manager->backupJson();
    exit();
}

if ($action === 'restore') {
    auth_require('data.manage', true);
    $file = $_FILES['archive'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        data_respond(['ok' => false, 'error' => 'No archive uploaded'], 400);
    }
    $json = (string)file_get_contents($file['tmp_name']);
    try {
        $counts = (new BackupManager($driver))->restoreJson($json);
        data_respond(['ok' => true, 'restored' => $counts]);
    } catch (Throwable $e) {
        data_respond(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($action === 'prune') {
    auth_require('data.manage', true);
    $days = isset($_REQUEST['days']) && $_REQUEST['days'] !== ''
        ? (int)$_REQUEST['days']
        : (int)($cloSettings['retentionDays'] ?? 0);
    if ($days <= 0) {
        data_respond(['ok' => false, 'error' => 'Set a positive number of days (or configure retentionDays).'], 400);
    }
    $result = (new RetentionManager($driver))->prune($days);
    data_respond(['ok' => true, 'days' => $days, 'cutoff' => $result['cutoff'], 'deleted' => $result['deleted']]);
}
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php' ?>
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="container-fluid" style="padding-top:20px">
        <h5 class="mb-3">Data — backup, restore &amp; retention</h5>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">Backup</h6>
                        <p class="text-muted small">Download a portable JSON archive of every table. Restorable onto either SQLite or MySQL.</p>
                        <a class="btn btn-sm btn-primary" href="data.php?action=backup">Download backup</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">Restore</h6>
                        <p class="text-muted small">Replace row data of existing tables from an archive. <strong>This overwrites current data.</strong></p>
                        <form id="restore-form">
                            <input type="file" class="form-control form-control-sm mb-2" name="archive" accept=".json,application/json" required>
                            <button class="btn btn-sm btn-warning" type="submit">Restore</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">Retention / pruning</h6>
                        <p class="text-muted small">Delete old rows from high-volume tables. Configured default: <code id="retention-default">–</code> day(s) (<code>retentionDays</code> in settings.php; cron: <code>php bin/run_retention.php</code>).</p>
                        <div class="input-group input-group-sm" style="max-width:320px">
                            <span class="input-group-text">Older than</span>
                            <input type="number" min="1" class="form-control" id="prune-days" placeholder="days">
                            <button class="btn btn-danger" id="prune-btn">Prune</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">Database</h6>
                        <p class="text-muted small">Driver: <code id="db-driver">–</code></p>
                        <div id="tables-table"></div>
                    </div>
                </div>
            </div>
        </div>
        <div id="data-result" class="mt-3"></div>
    </div>
</div>
<script>
function renderInfo(d) {
    document.getElementById('db-driver').textContent = d.driver || '?';
    document.getElementById('retention-default').textContent = (d.retentionDays || 0);
    let html = '<table class="table table-sm table-striped mb-0"><thead><tr><th>Table</th><th class="text-end">Rows</th></tr></thead><tbody>';
    (d.tables || []).forEach(t => {
        html += `<tr><td>${t.name}</td><td class="text-end">${t.rows}</td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('tables-table').innerHTML = html;
}
function loadInfo() {
    fetch('data.php?action=info').then(r => r.json()).then(renderInfo);
}
function showResult(cls, msg) {
    document.getElementById('data-result').innerHTML = `<div class="alert alert-${cls}">${msg}</div>`;
}
document.getElementById('restore-form').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!confirm('Restore will overwrite current data in matching tables. Continue?')) return;
    const fd = new FormData(this);
    showResult('info', 'Restoring…');
    fetch('data.php?action=restore', {method: 'POST', body: fd}).then(r => r.json()).then(d => {
        if (!d.ok) { showResult('danger', 'Restore failed: ' + (d.error || '')); return; }
        const rows = Object.entries(d.restored || {}).map(([k, v]) => `${k}: ${v}`).join(', ');
        showResult('success', 'Restored ' + rows);
        loadInfo();
    }).catch(() => showResult('danger', 'Restore failed.'));
});
document.getElementById('prune-btn').addEventListener('click', function() {
    const days = document.getElementById('prune-days').value;
    if (!confirm('Permanently delete rows older than ' + (days || 'the configured') + ' day(s)?')) return;
    showResult('info', 'Pruning…');
    fetch('data.php?action=prune&days=' + encodeURIComponent(days || '')).then(r => r.json()).then(d => {
        if (!d.ok) { showResult('danger', 'Prune failed: ' + (d.error || '')); return; }
        const rows = Object.entries(d.deleted || {}).map(([k, v]) => `${k}: ${v}`).join(', ') || 'nothing';
        showResult('success', `Pruned rows older than ${d.days} day(s) — ${rows}`);
        loadInfo();
    }).catch(() => showResult('danger', 'Prune failed.'));
});
loadInfo();
</script>
</body>
</html>
