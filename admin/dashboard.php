<?php
require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../reports/DashboardQuery.php';
global $db;

$action = $_GET['action'] ?? '';

if ($action === 'data') {
    header('Content-Type: application/json');

    $campId = (int)($_GET['campId'] ?? 0);
    $end = (int)($_GET['end'] ?? time());
    $start = (int)($_GET['start'] ?? ($end - 86400));
    $tz = (string)($_GET['tz'] ?? 'UTC');

    try {
        $dtz = new DateTimeZone($tz);
    } catch (Throwable $e) {
        $dtz = new DateTimeZone('UTC');
    }
    $offsetInSeconds = (new DateTime('now', $dtz))->getOffset();
    $hours = (int)floor($offsetInSeconds / 3600);
    $minutes = (int)floor(($offsetInSeconds % 3600) / 60);
    $tzOffset = sprintf('%+03d:%02d', $hours, $minutes);

    $q = new DashboardQuery($db->driver());
    echo json_encode([
        'summary' => $q->summary($campId, $start, $end),
        'series' => $q->timeseries($campId, $start, $end, $tzOffset),
        'top_country' => $q->topBy('country', $campId, $start, $end, 8),
        'top_flow' => $q->topBy('flow', $campId, $start, $end, 8),
    ]);
    exit;
}

$campaigns = $db->get_campaigns_list();
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php' ?>
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="container-fluid" style="padding-top:20px">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div>
                <label class="form-label mb-0 small">Campaign</label>
                <select id="dash-camp" class="form-select form-select-sm" style="width:auto">
                    <option value="0">All campaigns</option>
                    <?php foreach ($campaigns as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-0 small">Period</label>
                <select id="dash-range" class="form-select form-select-sm" style="width:auto">
                    <option value="3600">Last hour</option>
                    <option value="86400" selected>Last 24h</option>
                    <option value="604800">Last 7 days</option>
                    <option value="2592000">Last 30 days</option>
                </select>
            </div>
            <div>
                <label class="form-label mb-0 small">Auto-refresh</label>
                <select id="dash-refresh" class="form-select form-select-sm" style="width:auto">
                    <option value="0">Off</option>
                    <option value="10000">10s</option>
                    <option value="30000" selected>30s</option>
                    <option value="60000">60s</option>
                </select>
            </div>
            <button class="btn btn-sm btn-primary" id="dash-refresh-now">Refresh</button>
            <span id="dash-updated" class="text-muted small ms-2"></span>
        </div>

        <div id="dash-kpis" class="row g-2 mb-3"></div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Clicks &amp; Conversions over time</h6>
                <canvas id="dash-chart" height="110"></canvas>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h6 class="card-title">Top countries</h6>
                    <div id="dash-top-country"></div>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h6 class="card-title">Top flows</h6>
                    <div id="dash-top-flow"></div>
                </div></div>
            </div>
        </div>
    </div>
</div>
<script src="js/dashboard.js"></script>
</body>
</html>
