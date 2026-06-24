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
<link rel="stylesheet" href="<?= get_cloaker_path() ?>css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>" />
<body>
<?php include __DIR__ . '/header.php' ?>
<div class="all-content-wrapper">
    <div class="dash" id="dash">
        <div class="dash-head">
            <div class="dash-title">
                <h1><i class="bi bi-speedometer2"></i> Dashboard</h1>
                <span class="dash-sub">Real-time traffic performance overview</span>
            </div>
            <div class="dash-toolbar">
                <div class="dash-field">
                    <label for="dash-camp">Campaign</label>
                    <select id="dash-camp" class="dash-select">
                        <option value="0">All campaigns</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dash-field">
                    <label>Period</label>
                    <div class="dash-segment" id="dash-range" role="group" aria-label="Period">
                        <button type="button" data-range="3600">1H</button>
                        <button type="button" data-range="86400" class="active">24H</button>
                        <button type="button" data-range="604800">7D</button>
                        <button type="button" data-range="2592000">30D</button>
                    </div>
                </div>
                <div class="dash-field">
                    <label for="dash-refresh">Auto-refresh</label>
                    <select id="dash-refresh" class="dash-select">
                        <option value="0">Off</option>
                        <option value="10000">10s</option>
                        <option value="30000" selected>30s</option>
                        <option value="60000">60s</option>
                    </select>
                </div>
                <button class="dash-btn" id="dash-refresh-now" type="button" title="Refresh now">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <span id="dash-updated" class="dash-updated">
                    <span class="dash-live-dot" id="dash-live"></span><span id="dash-updated-text">Loading…</span>
                </span>
            </div>
        </div>

        <div id="dash-kpis" class="dash-kpis"></div>

        <div class="dash-grid">
            <div class="dash-card dash-chart-card">
                <div class="dash-card-head">
                    <h6><i class="bi bi-graph-up"></i> Clicks &amp; Conversions</h6>
                    <div class="dash-legend">
                        <span><i style="background:#3b9cff"></i> Clicks</span>
                        <span><i style="background:#2ecc8f"></i> Conversions</span>
                    </div>
                </div>
                <div class="dash-chart-wrap">
                    <canvas id="dash-chart" height="260"></canvas>
                    <div class="dash-tooltip" id="dash-chart-tip"></div>
                </div>
            </div>
            <div class="dash-card">
                <div class="dash-card-head">
                    <h6><i class="bi bi-shield-check"></i> Traffic quality</h6>
                </div>
                <div class="dash-quality" id="dash-quality"></div>
            </div>
        </div>

        <div class="dash-grid-2">
            <div class="dash-card">
                <div class="dash-card-head">
                    <h6><i class="bi bi-globe-americas"></i> Top countries</h6>
                </div>
                <div id="dash-top-country"></div>
            </div>
            <div class="dash-card">
                <div class="dash-card-head">
                    <h6><i class="bi bi-diagram-3"></i> Top flows</h6>
                </div>
                <div id="dash-top-flow"></div>
            </div>
        </div>
    </div>
</div>
<script src="js/dashboard.js?v=<?= filemtime(__DIR__ . '/js/dashboard.js') ?>"></script>
</body>
</html>
