<?php

/**
 * Cloudflare DNS automation endpoint (Phase 5). Generic and data-driven: it
 * reads Cloudflare credentials and the DNS record spec from a pool Domain's
 * settings, then calls the Cloudflare API to verify the token or create the
 * record. Never leaks the token in responses or logs.
 *
 * Actions (?action=): verify_token, create_record. Both take ?id= (domain id).
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../domains/CloudflareClient.php';

header('Content-Type: application/json; charset=utf-8');

global $db;
$driver = $db->driver();

function cf_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$id = (int)($_REQUEST['id'] ?? 0);
$action = (string)($_REQUEST['action'] ?? '');

$domain = $id > 0 ? Repositories::domains($driver)->findById($id) : null;
if (!$domain instanceof Domain) {
    cf_respond(['ok' => false, 'error' => 'Unknown domain'], 404);
}

$token = $domain->cfApiToken();
if ($token === '') {
    cf_respond(['ok' => false, 'error' => 'No Cloudflare API token set for this domain'], 400);
}

switch ($action) {
    case 'verify_token':
        $res = CloudflareClient::verifyToken($token);
        cf_respond(['ok' => $res['ok'], 'http_code' => $res['http_code'], 'error' => $res['error']]);
        break;

    case 'create_record':
        $res = CloudflareClient::createDnsRecord(
            $token,
            $domain->cfZoneId(),
            $domain->dnsType(),
            $domain->host(),
            $domain->dnsContent(),
            $domain->dnsProxied()
        );
        cf_respond(['ok' => $res['ok'], 'http_code' => $res['http_code'], 'error' => $res['error']]);
        break;

    default:
        cf_respond(['ok' => false, 'error' => 'Unknown action'], 400);
}
