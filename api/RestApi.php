<?php

require_once __DIR__ . '/../entities/EntityService.php';
require_once __DIR__ . '/../auth/AccessControl.php';

/**
 * Pure REST dispatcher for the entity CRUD API (Phase 11).
 *
 * Maps HTTP method + path to an {@see EntityService} operation and enforces the
 * caller's permissions ({@see AccessControl}). No transport concerns: callers
 * pass the already-resolved auth context and receive a status + payload, which
 * makes the whole routing/authorization layer unit-testable.
 *
 * Routes (relative to /api/rest.php):
 *   GET    /<type>          list
 *   GET    /<type>/<id>     get one
 *   POST   /<type>          create
 *   PUT    /<type>/<id>     update (full)
 *   PATCH  /<type>/<id>     update (partial)
 *   DELETE /<type>/<id>     delete
 */
class RestApi
{
    private EntityService $service;

    public function __construct(EntityService $service)
    {
        $this->service = $service;
    }

    /**
     * @param list<string>             $segments path segments after rest.php (e.g. ["offers","5"])
     * @param array<string,mixed>      $body     decoded request body
     * @param array{permissions:array<int,string>}|null $context auth context (null = unauthenticated)
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function handle(string $method, array $segments, array $body, ?array $context): array
    {
        if ($context === null) {
            return self::error(401, 'Unauthorized: provide a valid bearer token');
        }
        $segments = array_values(array_filter($segments, static fn($s) => $s !== ''));
        $type = $segments[0] ?? '';
        if ($type === '') {
            return self::error(404, 'No entity type in path');
        }
        $id = isset($segments[1]) ? (int)$segments[1] : null;
        $method = strtoupper($method);
        $perms = $context['permissions'] ?? [];

        try {
            $this->service->schema($type); // 404 for unknown type before anything else

            switch ($method) {
                case 'GET':
                    if (!AccessControl::permits($perms, "$type.view")) {
                        return self::forbidden($type, 'view');
                    }
                    if ($id !== null) {
                        $item = $this->service->get($type, $id);
                        return $item === null
                            ? self::error(404, 'Not found')
                            : self::ok(['item' => $item]);
                    }
                    return self::ok(['items' => $this->service->list($type)]);

                case 'POST':
                    if (!AccessControl::permits($perms, "$type.manage")) {
                        return self::forbidden($type, 'manage');
                    }
                    unset($body['id']);
                    return self::ok(['id' => $this->service->save($type, $body)], 201);

                case 'PUT':
                case 'PATCH':
                    if (!AccessControl::permits($perms, "$type.manage")) {
                        return self::forbidden($type, 'manage');
                    }
                    if ($id === null) {
                        return self::error(400, 'Missing id in path');
                    }
                    if ($method === 'PATCH') {
                        $current = $this->service->get($type, $id);
                        if ($current === null) {
                            return self::error(404, 'Not found');
                        }
                        // Merge partial change onto existing settings/name/group.
                        $body = array_merge(
                            ['name' => $current['name'], 'group' => $current['group']],
                            is_array($current['settings']) ? $current['settings'] : [],
                            $body
                        );
                    }
                    $body['id'] = $id;
                    return self::ok(['id' => $this->service->save($type, $body)]);

                case 'DELETE':
                    if (!AccessControl::permits($perms, "$type.manage")) {
                        return self::forbidden($type, 'manage');
                    }
                    if ($id === null) {
                        return self::error(400, 'Missing id in path');
                    }
                    return self::ok(['deleted' => $this->service->delete($type, $id)]);

                default:
                    return self::error(405, "Method $method not allowed");
            }
        } catch (EntityServiceException $e) {
            return self::error($e->status(), $e->getMessage());
        }
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private static function ok(array $payload, int $status = 200): array
    {
        return ['status' => $status, 'payload' => ['ok' => true] + $payload];
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'payload' => ['ok' => false, 'error' => $message]];
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private static function forbidden(string $type, string $action): array
    {
        return self::error(403, "Forbidden: missing permission $type.$action");
    }
}
