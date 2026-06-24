<?php
require_once __DIR__.'/dates.php';
require_once __DIR__.'/timezones.php';
require_once __DIR__.'/../debug.php';
require_once __DIR__.'/../paths.php';
require_once __DIR__.'/../auth/Auth.php';
function get_bases_version(): string
{
    $basesDir = __DIR__ . "/../bases";
    if (!is_dir($basesDir)) {
        return "Bases directory NOT FOUND";
    }

    $missing = [];
    foreach (['GeoLite2-Country.mmdb', 'GeoLite2-ASN.mmdb'] as $file) {
        if (!is_readable($basesDir . "/" . $file)) {
            $missing[] = $file;
        }
    }
    if (!empty($missing)) {
        return "Missing: " . implode(', ', $missing);
    }

    $updateFile = __DIR__ . "/../bases/update.txt";
    if (!file_exists($updateFile)) {
        return "Found";
    }
    return file_get_contents($updateFile);
}

function geoip_uses_dbip_lite(): bool
{
    $sourceFile = __DIR__ . "/../bases/source.txt";
    return is_readable($sourceFile) && stripos((string)file_get_contents($sourceFile), 'DB-IP') !== false;
}

$calDs = Dates::get_calend_dates();
$cdStr = $calDs[0] === $calDs[1] ? $calDs[0] : "{$calDs[0]} - {$calDs[1]}";

$headerPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$hideDateControl = $headerPage === 'campsettings.php';
$headerTimezone = 'UTC';
$headerTimezoneScope = 'common';
$headerCampId = null;

if (isset($c) && isset($c->statistics) && isset($c->statistics->timezone)) {
    $headerTimezone = $c->statistics->timezone;
    $headerTimezoneScope = 'campaign';
    $headerCampId = isset($campId) ? (int)$campId : null;
} elseif (isset($tz) && is_string($tz) && $tz !== '') {
    $headerTimezone = $tz;
    if (isset($campId) && $campId !== null && (($view ?? '') !== 'trafficback')) {
        $headerTimezoneScope = 'campaign';
        $headerCampId = (int)$campId;
    }
} elseif (isset($gs['statistics']['timezone'])) {
    $headerTimezone = $gs['statistics']['timezone'];
}

$headerDateConfig = [
    'enabled' => !$hideDateControl,
    'timezone' => $headerTimezone,
    'timezoneShort' => get_timezone_short_label($headerTimezone),
    'scope' => $headerTimezoneScope,
    'campId' => $headerCampId,
    'options' => get_timezone_options(),
];
?>
<div class="header-advance-area">
    <div class="header-top-area">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-6 col-md-6 col-sm-6 col-xs-6">
                    <div class="logo-pro">
                        <div class="logo-container">
                            <a href="index.php?startdate=<?=$calDs[0]?>&enddate=<?=$calDs[1]?>" class="logo-link yaaff-brand" aria-label="YaAff dashboard">
                                <span class="yaaff-brand-mark" aria-hidden="true">Y</span>
                                <span class="yaaff-brand-copy">
                                    <strong>YaAff</strong>
                                    <small>Affiliate traffic command center</small>
                                </span>
                            </a>
                            <div class="geo-version">
                                <?php 
                                    $basesVersion = get_bases_version(); 
                                    $basesClass = str_starts_with($basesVersion, 'Missing:') || str_ends_with($basesVersion, 'NOT FOUND') ? 'text-danger' : '';
                                    $basesEncoded = htmlspecialchars($basesVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                ?>
                                GeoBases: <a href="#" id="updateBases" title="Update bases" class="<?=$basesClass?>"><?=$basesEncoded?></a>
                                <?php if (geoip_uses_dbip_lite()): ?>
                                    <span class="geo-attribution">· <a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a></span>
                                <?php endif; ?>
                                <img style="width:30px; height:30px;display:none;" src="<?=get_cloaker_path()?>img/loading.apng" id="loadingAnimation" />
                                <?php if (DebugMethods::on()): ?>
                                <span class="yaaff-debug-badge">Debug Mode</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-sm-6 col-xs-6">
                    <div class="header-right-info">
                        <ul class="nav navbar-nav mai-top-nav header-right-menu">
                            <li class="nav-item">
                                <?php if (!$hideDateControl): ?>
                                <a class="nav-link" id='litepicker'>
                                    <i class="bi bi-calendar"></i>
                                    <span>
                                        Date:&nbsp;&nbsp;<?= $cdStr ?> · <?= htmlspecialchars(get_timezone_short_label($headerTimezone)) ?>
                                    </span>
                                </a>
                                <?php endif; ?>
                                <a class="nav-link" href="#" onclick="checkForUpdates(); return false;">
                                    <i class="bi bi-cloud-arrow-down"></i>
                                    <span>Update</span>
                                </a>
                                <a class="nav-link" href="logout.php">
                                    <i class="bi bi-door-closed"></i>
                                    <span>Logout</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
        $navItems = [
            'index.php'    => ['Campaigns', 'bi-megaphone', 'campaigns.view'],
            'dashboard.php' => ['Dashboard', 'bi-speedometer2', 'reports.view'],
            'offers.php'   => ['Offers', 'bi-bullseye', 'offers.view'],
            'landings.php' => ['Landings', 'bi-file-earmark-richtext', 'landings.view'],
            'sources.php'  => ['Sources', 'bi-broadcast', 'sources.view'],
            'networks.php' => ['Networks', 'bi-diagram-3', 'networks.view'],
            'domains.php'  => ['Domains', 'bi-globe2', 'domains.view'],
            'integrations.php' => ['Conversion APIs', 'bi-cloud-upload', 'integrations.view'],
            'conversions.php' => ['Conversions', 'bi-graph-up-arrow', 'conversions.view'],
            'blacklists.php' => ['Bot Protection', 'bi-shield-shaded', 'blacklists.view'],
            'rules.php' => ['Rules', 'bi-robot', 'rules.view'],
            'channels.php' => ['Notifications', 'bi-bell', 'channels.view'],
            'users.php' => ['Users', 'bi-people', 'users.view'],
            'roles.php' => ['Roles', 'bi-person-badge', 'roles.view'],
            'api.php' => ['REST API', 'bi-braces', null],
            'data.php' => ['Data', 'bi-database', 'data.view'],
        ];
    ?>
    <div class="entity-nav-area">
        <div class="container-fluid">
            <ul class="entity-nav">
                <?php foreach ($navItems as $file => $info): ?>
                    <?php if (isset($info[2]) && !auth_can($info[2])) continue; ?>
                    <li class="<?= $headerPage === $file ? 'active' : '' ?>">
                        <a href="<?= $file ?>"><i class="bi <?= $info[1] ?>"></i> <?= htmlspecialchars($info[0]) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

</div>
<script id="headerDateConfig" type="application/json">
    <?=json_encode($headerDateConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<div class="overlay" id="updateOverlay">
    <canvas id="matrix-rain"></canvas>
    <div class="grid-overlay"></div>
    <div class="updating-text">
        <span id="typing-text"></span><span class="cursor">█</span>
    </div>
</div>
