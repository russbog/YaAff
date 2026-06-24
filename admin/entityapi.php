<?php

/**
 * Generic JSON CRUD endpoint for first-class entities (Phase 1).
 *
 * Actions (via ?action=): list, get, save, delete. The entity type (?type=)
 * must be one of the registered types in Repositories::TYPES. All persistence
 * is driven by the declarative schema in entityschemas.php, so no per-entity
 * endpoint code is needed.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/entityschemas.php';
require_once __DIR__ . '/../auth/Auth.php';

header('Content-Type: application/json; charset=utf-8');

global $db;
$driver = $db->driver();

function respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$type = (string)($_REQUEST['type'] ?? '');
$schema = entity_schema($type);
$repo = $schema !== null ? Repositories::byType($driver, $type) : null;
if ($repo === null || $schema === null) {
    respond(['ok' => false, 'error' => 'Unknown entity type'], 400);
}

$action = (string)($_REQUEST['action'] ?? 'list');

// RBAC: viewing needs <type>.view, mutating needs <type>.manage. No-op in
// legacy single-password mode (no user accounts yet).
$needed = in_array($action, ['save', 'delete'], true) ? "$type.manage" : "$type.view";
auth_require($needed, true);

/** Mask secret fields (password hashes) before returning settings to the UI. */
function redact_settings(array $schema, array $settings): array
{
    foreach ($schema['fields'] as $field) {
        if (($field['type'] ?? '') === 'password') {
            $k = $field['key'];
            if (array_key_exists($k, $settings)) {
                $settings[$k] = (string)$settings[$k] !== '' ? '********' : '';
            }
        }
    }
    return $settings;
}

/** Resolve (or create) a group by name for this entity type; returns its id. */
function resolve_group_id(EntityRepository $groups, string $type, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    foreach ($groups->findAll() as $g) {
        /** @var Group $g */
        if (strcasecmp($g->name, $name) === 0 && $g->entityType() === $type) {
            return $g->id;
        }
    }
    $group = new Group();
    $group->name = $name;
    $group->set('entity_type', $type);
    return $groups->save($group)->id;
}

function group_name(EntityRepository $groups, ?int $id): string
{
    if ($id === null) {
        return '';
    }
    $g = $groups->find($id);
    return $g !== null ? $g->name : '';
}

/** Coerce a posted value into its stored representation per field type. */
function coerce_field(array $field, mixed $value): mixed
{
    $type = $field['type'] ?? 'text';
    switch ($type) {
        case 'number':
            return is_numeric($value) ? 0 + $value : 0;
        case 'checkbox':
            return (bool)$value;
        case 'entityref':
            return ($value === '' || $value === null) ? null : (int)$value;
        case 'csv':
            if (is_array($value)) {
                return array_values(array_filter(array_map('trim', $value), 'strlen'));
            }
            $parts = array_map('trim', explode(',', (string)$value));
            return array_values(array_filter($parts, 'strlen'));
        case 'kvlines':
            if (is_array($value)) {
                return $value;
            }
            $map = [];
            foreach (preg_split('/\r\n|\r|\n/', (string)$value) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if ($k !== '') {
                    $map[$k] = trim($v);
                }
            }
            return $map;
        case 'json':
            if (is_array($value)) {
                return $value;
            }
            $value = trim((string)$value);
            if ($value === '') {
                return null;
            }
            $decoded = json_decode($value, true);
            return $decoded;
        default:
            return (string)$value;
    }
}

$groups = Repositories::groups($driver);

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
        // no break

    case 'list':
        $rows = [];
        foreach ($repo->findAll([], 'updated_at', 'DESC') as $e) {
            $rows[] = [
                'id' => $e->id,
                'name' => $e->name,
                'group' => group_name($groups, $e->group_id),
                'settings' => redact_settings($schema, $e->settings),
                'updated_at' => $e->updated_at,
            ];
        }
        respond(['ok' => true, 'items' => $rows]);
        // no break (respond exits)

    case 'get':
        $id = (int)($_REQUEST['id'] ?? 0);
        $e = $repo->find($id);
        if ($e === null) {
            respond(['ok' => false, 'error' => 'Not found'], 404);
        }
        respond([
            'ok' => true,
            'item' => [
                'id' => $e->id,
                'name' => $e->name,
                'group' => group_name($groups, $e->group_id),
                'settings' => redact_settings($schema, $e->settings),
            ],
        ]);
        // no break

    case 'save':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            respond(['ok' => false, 'error' => 'Name is required'], 422);
        }

        $id = isset($body['id']) && $body['id'] !== '' ? (int)$body['id'] : null;
        $entity = $id !== null ? $repo->find($id) : null;
        if ($id !== null && $entity === null) {
            respond(['ok' => false, 'error' => 'Not found'], 404);
        }
        if ($entity === null) {
            [$class] = Repositories::TYPES[$type];
            $entity = new $class();
        }

        $entity->name = $name;
        $entity->group_id = resolve_group_id($groups, $type, (string)($body['group'] ?? ''));

        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            if ($key === 'name' || $key === 'group') {
                continue;
            }
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (($field['type'] ?? '') === 'password') {
                $plain = (string)$body[$key];
                if ($plain !== '') {
                    $entity->set($key, password_hash($plain, PASSWORD_DEFAULT));
                }
                continue;
            }
            $entity->set($key, coerce_field($field, $body[$key]));
        }

        $saved = $repo->save($entity);
        respond(['ok' => true, 'id' => $saved->id]);
        // no break

    case 'delete':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = (int)($body['id'] ?? $_REQUEST['id'] ?? 0);
        if ($id <= 0) {
            respond(['ok' => false, 'error' => 'Invalid id'], 422);
        }
        respond(['ok' => $repo->delete($id)]);
        // no break

    default:
        respond(['ok' => false, 'error' => 'Unknown action'], 400);
}
