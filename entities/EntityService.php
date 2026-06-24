<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/Repositories.php';
require_once __DIR__ . '/../admin/entityschemas.php';

/**
 * Raised on invalid input. Carries an HTTP status so both the admin endpoint
 * and the REST API can translate it into the right response code.
 */
class EntityServiceException extends RuntimeException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode() ?: 422;
    }
}

/**
 * Schema-driven CRUD for first-class entities (Phase 11).
 *
 * Single source of truth for persisting any registered entity type: field
 * coercion, password hashing, secret redaction and group resolution all live
 * here so the admin endpoint (admin/entityapi.php) and the REST API
 * (api/rest.php) behave identically. No HTTP/session concerns — callers handle
 * transport and authorization.
 */
class EntityService
{
    private DbDriver $driver;
    private EntityRepository $groups;

    public function __construct(DbDriver $driver)
    {
        $this->driver = $driver;
        $this->groups = Repositories::groups($driver);
    }

    /** @return array<string,mixed> schema or throw 404 for unknown type */
    public function schema(string $type): array
    {
        $schema = entity_schema($type);
        if ($schema === null) {
            throw new EntityServiceException('Unknown entity type', 404);
        }
        return $schema;
    }

    private function repo(string $type): EntityRepository
    {
        $repo = Repositories::byType($this->driver, $type);
        if ($repo === null) {
            throw new EntityServiceException('Unknown entity type', 404);
        }
        return $repo;
    }

    /** @return list<array<string,mixed>> */
    public function list(string $type): array
    {
        $schema = $this->schema($type);
        $rows = [];
        foreach ($this->repo($type)->findAll([], 'updated_at', 'DESC') as $e) {
            $rows[] = $this->present($schema, $e);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function get(string $type, int $id): ?array
    {
        $schema = $this->schema($type);
        $e = $this->repo($type)->find($id);
        return $e === null ? null : $this->present($schema, $e);
    }

    /**
     * Create or update from a posted associative array. The presence of a
     * non-empty `id` switches to update.
     *
     * @param array<string,mixed> $data
     */
    public function save(string $type, array $data): int
    {
        $schema = $this->schema($type);
        $repo = $this->repo($type);

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new EntityServiceException('Name is required', 422);
        }

        $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
        $entity = $id !== null ? $repo->find($id) : null;
        if ($id !== null && $entity === null) {
            throw new EntityServiceException('Not found', 404);
        }
        if ($entity === null) {
            [$class] = Repositories::TYPES[$type];
            $entity = new $class();
        }

        $entity->name = $name;
        $entity->group_id = $this->resolveGroupId($type, (string)($data['group'] ?? ''));

        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            if ($key === 'name' || $key === 'group' || !array_key_exists($key, $data)) {
                continue;
            }
            if (($field['type'] ?? '') === 'password') {
                $plain = (string)$data[$key];
                if ($plain !== '' && $plain !== self::REDACTED) {
                    $entity->set($key, password_hash($plain, PASSWORD_DEFAULT));
                }
                continue;
            }
            $entity->set($key, self::coerce($field, $data[$key]));
        }

        return (int)$repo->save($entity)->id;
    }

    public function delete(string $type, int $id): bool
    {
        $this->schema($type);
        if ($id <= 0) {
            throw new EntityServiceException('Invalid id', 422);
        }
        return $this->repo($type)->delete($id);
    }

    public const REDACTED = '********';

    /** @return array<string,mixed> presentation row with redacted secrets */
    private function present(array $schema, Entity $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'group' => $this->groupName($e->group_id),
            'settings' => self::redact($schema, $e->settings),
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ];
    }

    /**
     * Mask secret fields (password hashes) before returning settings.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function redact(array $schema, array $settings): array
    {
        foreach ($schema['fields'] as $field) {
            if (($field['type'] ?? '') === 'password') {
                $k = $field['key'];
                if (array_key_exists($k, $settings)) {
                    $settings[$k] = (string)$settings[$k] !== '' ? self::REDACTED : '';
                }
            }
        }
        return $settings;
    }

    /** Coerce a posted value into its stored representation per field type. */
    public static function coerce(array $field, mixed $value): mixed
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
                return json_decode($value, true);
            default:
                return (string)$value;
        }
    }

    /** Resolve (or create) a group by name for this entity type; returns its id. */
    private function resolveGroupId(string $type, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        foreach ($this->groups->findAll() as $g) {
            /** @var Group $g */
            if (strcasecmp($g->name, $name) === 0 && $g->entityType() === $type) {
                return $g->id;
            }
        }
        $group = new Group();
        $group->name = $name;
        $group->set('entity_type', $type);
        return $this->groups->save($group)->id;
    }

    private function groupName(?int $id): string
    {
        if ($id === null) {
            return '';
        }
        $g = $this->groups->find($id);
        return $g !== null ? $g->name : '';
    }
}
