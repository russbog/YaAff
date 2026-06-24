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

/** Stat fields exposed to the campaigns table, with display metadata. */
function spa_stat_fields(): array
{
    return [
        ['field' => 'clicks',      'title' => 'Clicks',      'kind' => 'int'],
        ['field' => 'uniques',     'title' => 'Uniques',     'kind' => 'int'],
        ['field' => 'uniques_ratio', 'title' => 'Unique %',  'kind' => 'pct'],
        ['field' => 'conversion',  'title' => 'Conversions', 'kind' => 'int'],
        ['field' => 'purchase',    'title' => 'Sales',       'kind' => 'int'],
        ['field' => 'cra',         'title' => 'CR (all)',    'kind' => 'pct'],
        ['field' => 'epc',         'title' => 'EPC',         'kind' => 'money'],
        ['field' => 'cpc',         'title' => 'CPC',         'kind' => 'money'],
        ['field' => 'revenue',     'title' => 'Revenue',     'kind' => 'money'],
        ['field' => 'costs',       'title' => 'Costs',       'kind' => 'money'],
        ['field' => 'profit',      'title' => 'Profit',      'kind' => 'money'],
        ['field' => 'roi',         'title' => 'ROI',         'kind' => 'pct'],
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
                'trafficBackUrl' => $gs['trafficBackUrl'] ?? '',
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
            spa_respond([
                'summary'     => $q->summary($campId, $start, $end),
                'series'      => $q->timeseries($campId, $start, $end, $tzOffset),
                'top_country' => $q->topBy('country', $campId, $start, $end, 8),
                'top_flow'    => $q->topBy('flow', $campId, $start, $end, 8),
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
            $range = Dates::get_time_range($tz);
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
            $range = Dates::get_time_range($tz);
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
            $db->set_common_settings($merged);
            spa_respond(['settings' => $merged]);
        }

        default:
            spa_error('Unknown route', 404);
    }
} catch (Throwable $e) {
    spa_error('Server error: ' . $e->getMessage(), 500);
}
