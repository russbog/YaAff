<?php

/**
 * Live health status for pool domains, consumed by the SPA domains list.
 *
 * Actions (?action=):
 *   all        GET  cached status map { id: record } for every domain (fast)
 *   check      POST live re-check of one domain (?id=), store + return it
 *   check_all  POST live re-check of every domain, store + return the map
 *   fix        POST remediate one domain (?id=) then re-check
 *
 * The cached map is what the table renders; live checks (DNS resolution + TLS
 * handshake) are kept off the render path. Auto-fix for direct (non-Cloudflare)
 * domains needs root, so from a web request it is reported as "queued" for the
 * root cron monitor (admin/domainmonitor.php).
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../bases/ipcountry.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../domains/DomainStatusChecker.php';
require_once __DIR__ . '/../domains/DomainStatusStore.php';
require_once __DIR__ . '/../domains/DomainFixer.php';
require_once __DIR__ . '/../domains/ServerIp.php';
require_once __DIR__ . '/../auth/Auth.php';

header('Content-Type: application/json; charset=utf-8');

global $db;
$driver = $db->driver();
$repo = Repositories::domains($driver);
$store = new DomainStatusStore();

function ds_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$action = (string)($_REQUEST['action'] ?? 'all');
$mutating = in_array($action, ['check', 'check_all', 'fix'], true);

// The SPA posts the domain id in a JSON body (only `action` rides in the query
// string), so parse it here with a form/query fallback.
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$reqId = (int)($body['id'] ?? $_REQUEST['id'] ?? 0);
auth_require($mutating ? 'domains.manage' : 'domains.view', true);

$checker = new DomainStatusChecker();
$serverIp = ServerIp::detect();

switch ($action) {
    case 'all': {
        // Only return records for domains that still exist.
        $ids = array_map(static fn(Domain $d) => (int)$d->id, $repo->findAll());
        $store->prune($ids);
        ds_respond(['ok' => true, 'serverIp' => $serverIp, 'statuses' => $store->all()]);
        // no break (ds_respond exits)
    }

    case 'check': {
        $id = $reqId;
        $domain = $id > 0 ? $repo->find($id) : null;
        if (!$domain instanceof Domain) {
            ds_respond(['ok' => false, 'error' => 'Unknown domain'], 404);
        }
        $record = $checker->check($domain, $serverIp);
        $store->put($id, $record);
        ds_respond(['ok' => true, 'status' => $record]);
        // no break
    }

    case 'check_all': {
        $out = [];
        foreach ($repo->findAll() as $domain) {
            /** @var Domain $domain */
            $record = $checker->check($domain, $serverIp);
            $store->put((int)$domain->id, $record);
            $out[(int)$domain->id] = $record;
        }
        ds_respond(['ok' => true, 'serverIp' => $serverIp, 'statuses' => $out]);
        // no break
    }

    case 'fix': {
        $id = $reqId;
        $domain = $id > 0 ? $repo->find($id) : null;
        if (!$domain instanceof Domain) {
            ds_respond(['ok' => false, 'error' => 'Unknown domain'], 404);
        }
        $fixer = new DomainFixer();
        $fix = $fixer->fix($domain, false); // web request → unprivileged
        // Re-check so the UI reflects the post-fix state immediately.
        $record = $checker->check($domain, $serverIp);
        $store->put($id, $record);
        ds_respond(['ok' => $fix['ok'], 'fix' => $fix, 'status' => $record]);
        // no break
    }

    default:
        ds_respond(['ok' => false, 'error' => 'Unknown action'], 400);
}
