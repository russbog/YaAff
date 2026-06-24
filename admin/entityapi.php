<?php

/**
 * Generic JSON CRUD endpoint for first-class entities (Phase 1).
 *
 * Actions (via ?action=): list, get, save, delete, templates. The entity type
 * (?type=) must be one of the registered types in Repositories::TYPES. All
 * persistence is delegated to the shared {@see EntityService} (Phase 11), which
 * the REST API reuses, so admin and API behave identically.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../entities/EntityService.php';
require_once __DIR__ . '/entityschemas.php';
require_once __DIR__ . '/../auth/Auth.php';

header('Content-Type: application/json; charset=utf-8');

global $db;
$service = new EntityService($db->driver());

function respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$type = (string)($_REQUEST['type'] ?? '');
$schema = entity_schema($type);
if ($schema === null) {
    respond(['ok' => false, 'error' => 'Unknown entity type'], 400);
}

$action = (string)($_REQUEST['action'] ?? 'list');

// RBAC: viewing needs <type>.view, mutating needs <type>.manage. No-op in
// legacy single-password mode (no user accounts yet).
$needed = in_array($action, ['save', 'delete'], true) ? "$type.manage" : "$type.view";
auth_require($needed, true);

try {
    switch ($action) {
        case 'templates':
            $dir = __DIR__ . '/../templates/' . $type;
            $items = [];
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                $data = json_decode((string)file_get_contents($file), true);
                if (!is_array($data)) {
                    continue;
                }
                $items[] = [
                    'name' => (string)($data['name'] ?? basename($file, '.json')),
                    'settings' => is_array($data['settings'] ?? null) ? $data['settings'] : [],
                ];
            }
            respond(['ok' => true, 'items' => $items]);
            // no break (respond exits)

        case 'list':
            respond(['ok' => true, 'items' => $service->list($type)]);
            // no break

        case 'get':
            $item = $service->get($type, (int)($_REQUEST['id'] ?? 0));
            if ($item === null) {
                respond(['ok' => false, 'error' => 'Not found'], 404);
            }
            respond(['ok' => true, 'item' => $item]);
            // no break

        case 'save':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = $_POST;
            }
            respond(['ok' => true, 'id' => $service->save($type, $body)]);
            // no break

        case 'delete':
            $body = json_decode(file_get_contents('php://input'), true);
            $id = (int)($body['id'] ?? $_REQUEST['id'] ?? 0);
            respond(['ok' => $service->delete($type, $id)]);
            // no break

        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (EntityServiceException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
}
