<?php
require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../currency.php';
require_once __DIR__ . '/../api/conversion_handler.php';
global $db;

$action = $_GET['action'] ?? '';

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $results = ['imported' => 0, 'skipped' => 0, 'errors' => []];

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Upload failed']);
        exit;
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) {
        echo json_encode(['error' => 'Cannot read file']);
        exit;
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        echo json_encode(['error' => 'Empty CSV']);
        exit;
    }
    $header = array_map('strtolower', array_map('trim', $header));

    $reqCols = ['clickid', 'status'];
    foreach ($reqCols as $col) {
        if (!in_array($col, $header, true)) {
            fclose($handle);
            echo json_encode(['error' => "Missing required column: $col"]);
            exit;
        }
    }

    $line = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        $data = array_combine($header, array_pad($row, count($header), ''));
        if (!$data) {
            $results['errors'][] = "Line $line: malformed";
            continue;
        }
        $clickid = trim($data['clickid'] ?? '');
        $status = trim($data['status'] ?? '');
        if ($clickid === '' || $status === '') {
            $results['errors'][] = "Line $line: missing clickid or status";
            $results['skipped']++;
            continue;
        }

        $click = $db->get_click_by_clickid($clickid);
        if (empty($click)) {
            $results['errors'][] = "Line $line: clickid $clickid not found";
            $results['skipped']++;
            continue;
        }
        $cs = $db->get_campaign_settings($click['campaign_id']);
        $c = new Campaign($click['campaign_id'], $cs);

        $inner_status = match (strtolower($status)) {
            strtolower($c->postback->leadStatusName), 'lead' => 'Lead',
            strtolower($c->postback->purchaseStatusName), 'purchase', 'sale' => 'Purchase',
            strtolower($c->postback->rejectStatusName), 'reject' => 'Reject',
            strtolower($c->postback->trashStatusName), 'trash' => 'Trash',
            default => ''
        };
        if ($inner_status === '') {
            $results['errors'][] = "Line $line: unknown status '$status'";
            $results['skipped']++;
            continue;
        }

        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $payout = is_numeric($data['payout'] ?? '') ? CurrencyConverter::convert($data['payout'], $currency) : 0.0;
        $revenue = is_numeric($data['revenue'] ?? '') ? CurrencyConverter::convert($data['revenue'], $currency) : (float)$payout;
        $tid = trim($data['tid'] ?? $data['transaction_id'] ?? '');

        $res = register_conversion($db, $c, $click, $inner_status, (float)$payout, (float)$revenue, $currency, $tid, $data, 'csv_import');
        if ($res['ok']) {
            $results['imported']++;
        } else {
            $results['skipped']++;
        }
    }
    fclose($handle);
    echo json_encode($results);
    exit;
}

if ($action === 'log') {
    header('Content-Type: application/json');
    $direction = $_GET['direction'] ?? '';
    $limit = min(1000, max(1, (int)($_GET['limit'] ?? 200)));
    echo json_encode($db->get_postback_log($limit, $direction));
    exit;
}

if ($action === 'list') {
    header('Content-Type: application/json');
    $start = (int)($_GET['start'] ?? (time() - 86400 * 30));
    $end = (int)($_GET['end'] ?? time());
    $campId = (int)($_GET['campId'] ?? 0);
    $limit = min(2000, max(1, (int)($_GET['limit'] ?? 500)));
    echo json_encode($db->get_conversions($start, $end, $campId, $limit));
    exit;
}
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php' ?>
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="container-fluid" style="padding-top:20px">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-conversions">Conversions</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-log">Postback Log</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-import">Bulk Import</a></li>
        </ul>
        <div class="tab-content" style="padding-top:15px">
            <div class="tab-pane active" id="tab-conversions">
                <div id="conversions-table"></div>
            </div>
            <div class="tab-pane" id="tab-log">
                <div class="mb-2">
                    <select id="log-direction" class="form-select form-select-sm" style="width:auto;display:inline-block">
                        <option value="">All</option>
                        <option value="in">Incoming</option>
                        <option value="out">Outgoing</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="loadLog()">Refresh</button>
                </div>
                <div id="log-table"></div>
            </div>
            <div class="tab-pane" id="tab-import">
                <p>Upload a CSV file with conversion data. Required columns: <code>clickid</code>, <code>status</code>. Optional: <code>payout</code>, <code>currency</code>, <code>revenue</code>, <code>tid</code>/<code>transaction_id</code>.</p>
                <form id="import-form" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required />
                    </div>
                    <button type="submit" class="btn btn-primary">Import</button>
                    <div id="import-result" style="margin-top:10px"></div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function loadConversions() {
    fetch('conversions.php?action=list')
        .then(r => r.json())
        .then(data => {
            let html = '<table class="table table-sm table-striped"><thead><tr><th>Time</th><th>Campaign</th><th>Click ID</th><th>TID</th><th>Status</th><th>Payout</th><th>Revenue</th><th>Currency</th><th>Source</th></tr></thead><tbody>';
            data.forEach(r => {
                let t = new Date(r.time * 1000).toLocaleString();
                html += `<tr><td>${t}</td><td>${r.campaign_id}</td><td>${r.clickid}</td><td>${r.tid||''}</td><td>${r.status}</td><td>${r.payout}</td><td>${r.revenue}</td><td>${r.currency}</td><td>${r.source||''}</td></tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('conversions-table').innerHTML = html;
        });
}
function loadLog() {
    let dir = document.getElementById('log-direction').value;
    fetch('conversions.php?action=log&direction=' + dir)
        .then(r => r.json())
        .then(data => {
            let html = '<table class="table table-sm table-striped"><thead><tr><th>Time</th><th>Dir</th><th>Click ID</th><th>Status</th><th>Payout</th><th>Target</th><th>Code</th><th>Message</th></tr></thead><tbody>';
            data.forEach(r => {
                let t = new Date(r.time * 1000).toLocaleString();
                html += `<tr><td>${t}</td><td>${r.direction}</td><td>${r.clickid}</td><td>${r.status}</td><td>${r.payout}</td><td>${r.target||''}</td><td>${r.http_code}</td><td>${(r.message||'').substring(0,120)}</td></tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('log-table').innerHTML = html;
        });
}
document.getElementById('import-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    let fd = new FormData(this);
    let res = await fetch('conversions.php?action=import', { method: 'POST', body: fd });
    let js = await res.json();
    let el = document.getElementById('import-result');
    if (js.error) {
        el.innerHTML = '<div class="alert alert-danger">' + js.error + '</div>';
    } else {
        let errHtml = js.errors.length ? '<br><small>' + js.errors.slice(0,20).join('<br>') + '</small>' : '';
        el.innerHTML = `<div class="alert alert-success">Imported: ${js.imported}, Skipped: ${js.skipped}${errHtml}</div>`;
    }
});
loadConversions();
</script>
</body>
</html>
