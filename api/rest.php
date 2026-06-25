<?php

/**
 * REST API HTTP entry point (Phase 11).
 *
 * Thin transport glue around the pure {@see RestApi} dispatcher: resolves the
 * bearer token to a permission context, parses the path/body and emits JSON.
 *
 * Path style (PATH_INFO):   /api/rest.php/offers           /api/rest.php/offers/5
 * Query fallback:           /api/rest.php?type=offers&id=5
 */

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../domainguard.php';
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/RestApi.php';

// The management REST API is a system surface — not reachable on pool domains.
deny_system_path_on_pool_domain();

header('Content-Type: application/json; charset=utf-8');

global $db, $cloSettings;

$bearer = ApiAuth::bearerFromRequest();
$context = ApiAuth::resolve($db->driver(), $bearer, (string)($cloSettings['apiToken'] ?? ''));

// Path segments: prefer PATH_INFO, fall back to ?type=&id=.
$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo !== '') {
    $segments = explode('/', trim($pathInfo, '/'));
} else {
    $segments = array_values(array_filter([
        (string)($_GET['type'] ?? ''),
        isset($_GET['id']) ? (string)$_GET['id'] : '',
    ], static fn($s) => $s !== ''));
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    $body = is_array($decoded) ? $decoded : $_POST;
}

$api = new RestApi(new EntityService($db->driver()));
$result = $api->handle($method, $segments, $body, $context);

http_response_code($result['status']);
echo json_encode($result['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
