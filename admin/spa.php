<?php

/**
 * Consolidated JSON API for the YaAff modern admin SPA.
 *
 * One session-authenticated entry point that aggregates the data the React UI
 * needs and delegates every mutation to the existing services (EntityService,
 * campaign settings, DashboardQuery, paginated clicks). No business logic lives
 * here — it is a thin read/aggregation layer so the SPA and the legacy pages
 * stay in lockstep.
 *
 * Routes (?r=):
 *   bootstrap            app shell data: version, permissions, nav, schemas, settings
 *   campaigns            campaigns list with aggregated stats for the active range
 *   campaign             GET one campaign's settings (?id=)
 *   dashboard            proxy to DashboardQuery (?campId&start&end&tz)
 *   clicks               proxy to paginated clicks (?campId&view&page&size&sort&dir&search)
 *   conversions          recent conversions (?campId&limit)
 *   common-settings      POST: persist (merged) common settings
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/clmns.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/timezones.php';
require_once __DIR__ . '/entityschemas.php';
require_once __DIR__ . '/../auth/Auth.php';
require_once __DIR__ . '/../reports/DashboardQuery.php';

header('Content-Type: application/json; charset=utf-8');

global $db, $cloSettings;

function spa_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function spa_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function spa_body(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

/** GeoIP database status (version, missing files, source) for the SPA topbar. */
function spa_geobases(): array
{
    $basesDir = __DIR__ . '/../bases';
    $missing = [];
    foreach (['GeoLite2-Country.mmdb', 'GeoLite2-ASN.mmdb'] as $file) {
        if (!is_readable($basesDir . '/' . $file)) {
            $missing[] = $file;
        }
    }
    $updateFile = $basesDir . '/update.txt';
    $version = is_readable($updateFile) ? trim((string)file_get_contents($updateFile)) : '';
    $sourceFile = $basesDir . '/source.txt';
    $source = is_readable($sourceFile) ? trim((string)file_get_contents($sourceFile)) : null;
    return ['version' => $version, 'missing' => $missing, 'source' => $source];
}

/**
 * Catalog of statistic columns offered across the SPA (campaigns grid, reports,
 * column picker). Each entry carries display metadata so the front-end can group,
 * describe and format columns consistently:
 *   - field   : SQL alias produced by Db::get_stats_select_parts()
 *   - title   : short column label
 *   - kind    : 'int' | 'pct' | 'money' (drives number formatting)
 *   - cat     : grouping category for the column picker
 *   - desc    : helper tooltip shown on the column header
 *   - better  : 'high' | 'low' — which direction is good (for tone coding); omit if neutral
 *   - default : false to keep the column hidden until the user enables it (defaults to true)
 * Every field here must be computable by Db::get_stats_select_parts().
 */
function spa_stat_fields(): array
{
    return [
        // Volume
        ['field' => 'clicks',        'title' => 'Clicks',     'kind' => 'int',   'cat' => 'Volume',      'desc' => 'Total clicks (visits) in the period.',                       'better' => 'high'],
        ['field' => 'uniques',       'title' => 'Uniques',    'kind' => 'int',   'cat' => 'Volume',      'desc' => 'Unique visitors (distinct user id).',                        'better' => 'high'],
        ['field' => 'uniques_ratio', 'title' => 'Unique %',   'kind' => 'pct',   'cat' => 'Volume',      'desc' => 'Share of unique visitors among all clicks.',                 'better' => 'high'],
        // Conversions
        ['field' => 'conversion',    'title' => 'Conversions','kind' => 'int',   'cat' => 'Conversions', 'desc' => 'Clicks that produced any postback status.',                  'better' => 'high'],
        ['field' => 'purchase',      'title' => 'Sales',      'kind' => 'int',   'cat' => 'Conversions', 'desc' => 'Conversions with status "Purchase".',                        'better' => 'high'],
        ['field' => 'hold',          'title' => 'Leads',      'kind' => 'int',   'cat' => 'Conversions', 'desc' => 'Conversions with status "Lead" (on hold).',                  'better' => 'high', 'default' => false],
        ['field' => 'reject',        'title' => 'Rejected',   'kind' => 'int',   'cat' => 'Conversions', 'desc' => 'Conversions with status "Reject".',                          'better' => 'low',  'default' => false],
        ['field' => 'trash',         'title' => 'Trash',      'kind' => 'int',   'cat' => 'Conversions', 'desc' => 'Conversions with status "Trash".',                           'better' => 'low',  'default' => false],
        // Rates
        ['field' => 'cra',           'title' => 'CR (all)',   'kind' => 'pct',   'cat' => 'Rates',       'desc' => 'Conversion rate: conversions / clicks.',                     'better' => 'high'],
        ['field' => 'crs',           'title' => 'CR (sales)', 'kind' => 'pct',   'cat' => 'Rates',       'desc' => 'Sales conversion rate: sales / clicks.',                     'better' => 'high', 'default' => false],
        ['field' => 'app',           'title' => 'Approve %',  'kind' => 'pct',   'cat' => 'Rates',       'desc' => 'Approved sales as a share of all conversions.',              'better' => 'high', 'default' => false],
        ['field' => 'appt',          'title' => 'Approve % (ex. trash)', 'kind' => 'pct', 'cat' => 'Rates', 'desc' => 'Approved sales as a share of conversions, excluding trash.', 'better' => 'high', 'default' => false],
        // Cost
        ['field' => 'cpc',           'title' => 'CPC',        'kind' => 'money', 'cat' => 'Cost',        'desc' => 'Cost per click: cost / clicks.',                             'better' => 'low'],
        ['field' => 'ucpc',          'title' => 'uCPC',       'kind' => 'money', 'cat' => 'Cost',        'desc' => 'Cost per unique click: cost / uniques.',                     'better' => 'low',  'default' => false],
        ['field' => 'cpa',           'title' => 'CPA',        'kind' => 'money', 'cat' => 'Cost',        'desc' => 'Cost per conversion: cost / conversions.',                   'better' => 'low',  'default' => false],
        ['field' => 'costs',         'title' => 'Cost',       'kind' => 'money', 'cat' => 'Cost',        'desc' => 'Total traffic cost in the period.',                          'better' => 'low'],
        // Earnings
        ['field' => 'epc',           'title' => 'EPC',        'kind' => 'money', 'cat' => 'Earnings',    'desc' => 'Earnings per click: revenue / clicks.',                      'better' => 'high'],
        ['field' => 'uepc',          'title' => 'uEPC',       'kind' => 'money', 'cat' => 'Earnings',    'desc' => 'Earnings per unique click: revenue / uniques.',              'better' => 'high', 'default' => false],
        ['field' => 'ec',            'title' => 'EC',         'kind' => 'money', 'cat' => 'Earnings',    'desc' => 'Earnings per conversion: revenue / conversions.',            'better' => 'high', 'default' => false],
        ['field' => 'revenue',       'title' => 'Revenue',    'kind' => 'money', 'cat' => 'Earnings',    'desc' => 'Total payout earned in the period.',                         'better' => 'high'],
        ['field' => 'profit',        'title' => 'Profit',     'kind' => 'money', 'cat' => 'Earnings',    'desc' => 'Revenue minus cost.',                                        'better' => 'high'],
        ['field' => 'roi',           'title' => 'ROI',        'kind' => 'pct',   'cat' => 'Earnings',    'desc' => 'Return on investment: profit / cost.',                       'better' => 'high'],
    ];
}

/**
 * Grouping dimensions offered by the custom report builder. Each maps to a
 * column understood by Db::get_statistics() (built-in click columns plus the
 * special "date" bucket). Stacking several builds a nested report tree.
 */
function spa_groupby_dimensions(): array
{
    return [
        ['field' => 'date',      'label' => 'Date',        'desc' => 'Day bucket in the reporting timezone.'],
        ['field' => 'country',   'label' => 'Country',     'desc' => 'Visitor country (GeoIP).'],
        ['field' => 'isp',       'label' => 'ISP',         'desc' => 'Internet service provider / carrier.'],
        ['field' => 'lang',      'label' => 'Language',    'desc' => 'Browser language.'],
        ['field' => 'os',        'label' => 'OS',          'desc' => 'Operating system family.'],
        ['field' => 'osver',     'label' => 'OS version',  'desc' => 'Operating system version.'],
        ['field' => 'device',    'label' => 'Device',      'desc' => 'Device type (mobile / desktop / tablet).'],
        ['field' => 'brand',     'label' => 'Brand',       'desc' => 'Device brand.'],
        ['field' => 'model',     'label' => 'Model',       'desc' => 'Device model.'],
        ['field' => 'client',    'label' => 'Browser',     'desc' => 'Client (browser) name.'],
        ['field' => 'clientver', 'label' => 'Browser ver', 'desc' => 'Client (browser) version.'],
        ['field' => 'flow',      'label' => 'Flow',        'desc' => 'Traffic flow that handled the click.'],
        ['field' => 'step',      'label' => 'Step',        'desc' => 'Funnel step index.'],
    ];
}

function spa_nav(): array
{
    $items = [
        ['key' => 'campaigns',    'label' => 'Campaigns',     'icon' => 'megaphone',   'perm' => 'campaigns.view'],
        ['key' => 'dashboard',    'label' => 'Dashboard',     'icon' => 'gauge',       'perm' => 'reports.view'],
        ['key' => 'reports',      'label' => 'Reports',       'icon' => 'table',       'perm' => 'campaigns.view'],
        ['key' => 'offers',       'label' => 'Offers',        'icon' => 'target',      'perm' => 'offers.view'],
        ['key' => 'landings',     'label' => 'Landings',      'icon' => 'file',        'perm' => 'landings.view'],
        ['key' => 'sources',      'label' => 'Sources',       'icon' => 'broadcast',   'perm' => 'sources.view'],
        ['key' => 'networks',     'label' => 'Networks',      'icon' => 'sitemap',     'perm' => 'networks.view'],
        ['key' => 'domains',      'label' => 'Domains',       'icon' => 'globe',       'perm' => 'domains.view'],
        ['key' => 'integrations', 'label' => 'Conversion APIs', 'icon' => 'cloud',     'perm' => 'integrations.view'],
        ['key' => 'conversions',  'label' => 'Conversions',   'icon' => 'trend',       'perm' => 'conversions.view'],
        ['key' => 'blacklists',   'label' => 'Bot Protection', 'icon' => 'shield',     'perm' => 'blacklists.view'],
        ['key' => 'rules',        'label' => 'Rules',         'icon' => 'robot',       'perm' => 'rules.view'],
        ['key' => 'channels',     'label' => 'Notifications', 'icon' => 'bell',        'perm' => 'channels.view'],
        ['key' => 'users',        'label' => 'Users',         'icon' => 'users',       'perm' => 'users.view'],
        ['key' => 'roles',        'label' => 'Roles',         'icon' => 'badge',       'perm' => 'roles.view'],
        ['key' => 'api',          'label' => 'REST API',      'icon' => 'braces',      'perm' => null],
        ['key' => 'data',         'label' => 'Data',          'icon' => 'database',    'perm' => 'data.view'],
    ];
    return array_values(array_filter($items, static fn($i) => $i['perm'] === null || auth_can($i['perm'])));
}

function spa_permission_map(): array
{
    $types = ['campaigns', 'reports', 'offers', 'landings', 'sources', 'networks', 'domains',
        'integrations', 'conversions', 'blacklists', 'rules', 'channels', 'users', 'roles', 'data', 'groups'];
    $map = [];
    foreach ($types as $t) {
        $map["$t.view"] = auth_can("$t.view");
        $map["$t.manage"] = auth_can("$t.manage");
    }
    return $map;
}

$route = (string)($_GET['r'] ?? '');

try {
    switch ($route) {
        case 'bootstrap': {
            $gs = $db->get_common_settings();
            $user = auth_current_user();
            spa_respond([
                // Version format: YY.MM.DD.mm (mm = minutes since midnight, UTC). See admin/autoupdate.php.
                'version'      => trim(@file_get_contents(__DIR__ . '/version.txt') ?: ''),
                'multiuser'    => auth_multiuser(),
                'user'         => $user ? ['name' => $user['name'], 'role' => $user['role']] : null,
                'permissions'  => spa_permission_map(),
                'nav'          => spa_nav(),
                'entitySchemas' => entity_schemas(),
                'commonSettings' => $gs,
                'campaignsList' => $db->get_campaigns_list(),
                'timezones'    => get_timezone_options(),
                'statFields'   => spa_stat_fields(),
                'groupByDims'  => spa_groupby_dimensions(),
                'trafficBackUrl' => $gs['trafficBackUrl'] ?? '',
                'geoBases'     => spa_geobases(),
            ]);
        }

        case 'campaigns': {
            auth_require('campaigns.view', true);
            $gs = $db->get_common_settings();
            $tz = $gs['statistics']['timezone'] ?? 'UTC';
            $savedFilters = $gs['statistics']['campaignsFilters'] ?? [];
            $end = isset($_GET['end']) ? (int)$_GET['end'] : null;
            $start = isset($_GET['start']) ? (int)$_GET['start'] : null;
            if ($start !== null && $end !== null) {
                $range = [$start, $end];
            } else {
                $range = Dates::get_time_range($tz);
            }
            $fields = array_map('strval', array_column(spa_stat_fields(), 'field'));
            $rows = $db->get_campaigns($range[0], $range[1], $fields, $savedFilters);
            spa_respond([
                'rows'       => $rows,
                'statFields' => spa_stat_fields(),
                'range'      => ['start' => $range[0], 'end' => $range[1], 'tz' => $tz],
                'filters'    => $savedFilters,
            ]);
        }

        case 'campaign': {
            auth_require('campaigns.view', true);
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                spa_error('Missing campaign id');
            }
            $settings = $db->get_campaign_settings($id);
            spa_respond(['id' => $id, 'settings' => $settings]);
        }

        case 'report': {
            auth_require('reports.view', true);
            $campId = (int)($_GET['campId'] ?? 0);
            if ($campId <= 0) {
                spa_error('Missing campaign id');
            }
            $gs = $db->get_common_settings();
            $cs = $db->get_campaign_settings($campId);
            $tz = $cs['statistics']['timezone'] ?? ($gs['statistics']['timezone'] ?? 'UTC');

            // Allowed grouping dimensions and stat fields (whitelist against catalogs).
            $allowedDims = array_column(spa_groupby_dimensions(), 'field');
            $allowedStats = array_column(spa_stat_fields(), 'field');

            $rawGroup = $_GET['groupBy'] ?? [];
            if (is_string($rawGroup)) {
                $rawGroup = $rawGroup === '' ? [] : explode(',', $rawGroup);
            }
            $groupBy = array_values(array_filter(
                array_map('strval', (array)$rawGroup),
                static fn($f) => in_array($f, $allowedDims, true)
            ));
            $groupBy = array_slice(array_values(array_unique($groupBy)), 0, 5);

            $rawFields = $_GET['fields'] ?? [];
            if (is_string($rawFields)) {
                $rawFields = $rawFields === '' ? [] : explode(',', $rawFields);
            }
            $fields = array_values(array_filter(
                array_map('strval', (array)$rawFields),
                static fn($f) => in_array($f, $allowedStats, true)
            ));
            if (empty($fields)) {
                $fields = ['clicks', 'uniques', 'conversion', 'revenue', 'costs', 'profit', 'roi'];
            }

            // Derived rate metrics (roi, cra, epc, …) are computed from additive
            // base metrics; always feed those bases to the aggregator so a node's
            // derived columns never divide by a missing key. Extra keys are
            // ignored by the frontend, which renders only the requested $fields.
            $baseFields = ['clicks', 'uniques', 'conversion', 'purchase', 'trash', 'revenue', 'costs'];
            $computeFields = array_values(array_unique(array_merge($fields, $baseFields)));

            $end = isset($_GET['end']) ? (int)$_GET['end'] : null;
            $start = isset($_GET['start']) ? (int)$_GET['start'] : null;
            $range = ($start !== null && $end !== null && $start < $end)
                ? [$start, $end]
                : Dates::get_time_range($tz);

            $tree = $db->get_statistics($computeFields, $groupBy, $campId, (string)$range[0], (string)$range[1], $tz, [], []);
            spa_respond([
                'tree'       => $tree,
                'groupBy'    => $groupBy,
                'fields'     => $fields,
                'dimensions' => spa_groupby_dimensions(),
                'statFields' => spa_stat_fields(),
                'range'      => ['start' => $range[0], 'end' => $range[1], 'tz' => $tz],
            ]);
        }

        case 'dashboard': {
            auth_require('reports.view', true);
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
            $absOffset = abs($offsetInSeconds);
            $sign = $offsetInSeconds >= 0 ? '+' : '-';
            $tzOffset = sprintf('%s%02d:%02d', $sign, (int)floor($absOffset / 3600), (int)floor(($absOffset % 3600) / 60));
            $q = new DashboardQuery($db->driver());
            // DashboardQuery speaks the report vocabulary (name/clicks, bucket);
            // the SPA charts expect {label,value} rows and a {t,...} series, so
            // adapt the shapes here without touching the query layer or its tests.
            $topRows = static fn(array $rows): array => array_map(
                static fn(array $r): array => [
                    'label' => (string)($r['name'] ?? ''),
                    'value' => (int)($r['clicks'] ?? 0),
                ],
                $rows
            );
            $series = array_map(
                static fn(array $r): array => [
                    't'           => (string)($r['bucket'] ?? ''),
                    'clicks'      => (int)($r['clicks'] ?? 0),
                    'conversions' => (int)($r['conversions'] ?? 0),
                    'revenue'     => (float)($r['revenue'] ?? 0),
                ],
                $q->timeseries($campId, $start, $end, $tzOffset)
            );
            spa_respond([
                'summary'     => $q->summary($campId, $start, $end),
                'series'      => $series,
                'top_country' => $topRows($q->topBy('country', $campId, $start, $end, 8)),
                'top_flow'    => $topRows($q->topBy('flow', $campId, $start, $end, 8)),
            ]);
        }

        case 'clicks': {
            auth_require('campaigns.view', true);
            $campId = isset($_GET['campId']) ? (int)$_GET['campId'] : null;
            $view = (string)($_GET['view'] ?? 'allowed');
            if (!in_array($view, ['allowed', 'blocked', 'leads', 'trafficback'], true)) {
                $view = 'allowed';
            }
            $gs = $db->get_common_settings();
            $tz = $gs['statistics']['timezone'] ?? 'UTC';
            if ($view !== 'trafficback' && $campId) {
                $cs = $db->get_campaign_settings($campId);
                $tz = $cs['statistics']['timezone'] ?? $tz;
            }
            $end = isset($_GET['end']) ? (int)$_GET['end'] : null;
            $start = isset($_GET['start']) ? (int)$_GET['start'] : null;
            if ($start !== null && $end !== null && $start < $end) {
                $range = [$start, $end];
            } else {
                $range = Dates::get_time_range($tz);
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $size = max(1, min(5000, (int)($_GET['size'] ?? 200)));
            $sortField = (string)($_GET['sort'] ?? 'time');
            $sortDir = (string)($_GET['dir'] ?? 'desc');
            $search = trim((string)($_GET['search'] ?? ''));
            if ($view !== 'trafficback' && !$campId) {
                spa_respond(['last_page' => 1, 'data' => []]);
            }
            $result = $db->get_clicks_paginated($view, $range[0], $range[1], $campId, $page, $size, $sortField, $sortDir, [], [], $search);
            spa_respond($result);
        }

        case 'conversions': {
            auth_require('conversions.view', true);
            $gs = $db->get_common_settings();
            $tz = $gs['statistics']['timezone'] ?? 'UTC';
            $end = isset($_GET['end']) ? (int)$_GET['end'] : null;
            $start = isset($_GET['start']) ? (int)$_GET['start'] : null;
            $range = ($start !== null && $end !== null && $start < $end)
                ? [$start, $end]
                : Dates::get_time_range($tz);
            $campId = (int)($_GET['campId'] ?? 0);
            $limit = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
            spa_respond(['data' => $db->get_conversions($range[0], $range[1], $campId, $limit)]);
        }

        case 'common-settings': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                spa_error('POST required', 405);
            }
            auth_require('campaigns.manage', true);
            $body = spa_body();
            $current = $db->get_common_settings();
            $merged = array_replace_recursive($current, $body);
            // array_replace_recursive merges list values by index, which corrupts
            // a reordered or trimmed list (trailing old items survive). For ordered
            // preference lists, take the incoming value verbatim.
            if (isset($body['statistics']['campaignsColumns'])) {
                $merged['statistics']['campaignsColumns'] = $body['statistics']['campaignsColumns'];
            }
            $db->set_common_settings($merged);
            spa_respond(['settings' => $merged]);
        }

        default:
            spa_error('Unknown route', 404);
    }
} catch (Throwable $e) {
    spa_error('Server error: ' . $e->getMessage(), 500);
}
