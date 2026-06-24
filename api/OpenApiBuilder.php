<?php

require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../admin/entityschemas.php';

/**
 * Generates an OpenAPI 3.0 document from the entity schemas (Phase 11).
 *
 * Pure and data-driven: every registered entity type ({@see Repositories::TYPES}
 * that has a schema) contributes a component schema and a full CRUD path set, so
 * the spec stays in sync with the admin/REST layer automatically — no hand-kept
 * duplicate of the model.
 */
class OpenApiBuilder
{
    /** @return array<string,mixed> */
    public static function build(string $basePath = '/api/rest.php'): array
    {
        $paths = [];
        $schemas = [];

        foreach (array_keys(Repositories::TYPES) as $type) {
            $schema = entity_schema($type);
            if ($schema === null) {
                continue;
            }
            $modelName = self::pascal($type);
            $schemas[$modelName] = self::modelSchema($schema);

            $tag = (string)($schema['title'] ?? $modelName);
            $ref = ['$ref' => "#/components/schemas/$modelName"];

            $paths["$basePath/$type"] = [
                'get' => self::op($tag, "List $type", [
                    '200' => self::jsonResponse('OK', [
                        'type' => 'object',
                        'properties' => [
                            'ok' => ['type' => 'boolean'],
                            'items' => ['type' => 'array', 'items' => $ref],
                        ],
                    ]),
                ]),
                'post' => self::op($tag, "Create $type", [
                    '201' => self::jsonResponse('Created', self::idResponse()),
                    '422' => self::errorResponse('Validation error'),
                ], $ref),
            ];

            $paths["$basePath/$type/{id}"] = [
                'parameters' => [self::idParam()],
                'get' => self::op($tag, "Get $type by id", [
                    '200' => self::jsonResponse('OK', [
                        'type' => 'object',
                        'properties' => ['ok' => ['type' => 'boolean'], 'item' => $ref],
                    ]),
                    '404' => self::errorResponse('Not found'),
                ]),
                'put' => self::op($tag, "Replace $type", [
                    '200' => self::jsonResponse('OK', self::idResponse()),
                    '404' => self::errorResponse('Not found'),
                ], $ref),
                'patch' => self::op($tag, "Update $type fields", [
                    '200' => self::jsonResponse('OK', self::idResponse()),
                    '404' => self::errorResponse('Not found'),
                ], $ref),
                'delete' => self::op($tag, "Delete $type", [
                    '200' => self::jsonResponse('OK', [
                        'type' => 'object',
                        'properties' => ['ok' => ['type' => 'boolean'], 'deleted' => ['type' => 'boolean']],
                    ]),
                    '404' => self::errorResponse('Not found'),
                ]),
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'YaAff Tracker REST API',
                'version' => '1.0.0',
                'description' => 'Generic CRUD over all first-class entities. '
                    . 'Authenticate with "Authorization: Bearer <token>" (per-user api_token or the configured master apiToken).',
            ],
            'servers' => [['url' => '/']],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => $schemas,
            ],
            'paths' => $paths,
        ];
    }

    /**
     * @param array<string,mixed> $responses
     * @param array<string,mixed>|null $requestRef
     * @return array<string,mixed>
     */
    private static function op(string $tag, string $summary, array $responses, ?array $requestRef = null): array
    {
        $op = ['tags' => [$tag], 'summary' => $summary, 'responses' => $responses];
        if ($requestRef !== null) {
            $op['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => $requestRef]],
            ];
        }
        return $op;
    }

    /** @return array<string,mixed> */
    private static function modelSchema(array $schema): array
    {
        $props = [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'name' => ['type' => 'string'],
            'group' => ['type' => 'string'],
        ];
        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            if ($key === 'name' || $key === 'group') {
                continue;
            }
            $prop = self::fieldSchema($field);
            if (!empty($field['help'])) {
                $prop['description'] = (string)$field['help'];
            }
            $props[$key] = $prop;
        }
        return ['type' => 'object', 'properties' => $props];
    }

    /** @return array<string,mixed> */
    private static function fieldSchema(array $field): array
    {
        switch ($field['type'] ?? 'text') {
            case 'number':
                return ['type' => 'number'];
            case 'checkbox':
                return ['type' => 'boolean'];
            case 'entityref':
                return ['type' => 'integer', 'nullable' => true];
            case 'csv':
                return ['type' => 'array', 'items' => ['type' => 'string']];
            case 'kvlines':
            case 'json':
                return ['type' => 'object', 'additionalProperties' => true];
            case 'password':
                return ['type' => 'string', 'format' => 'password', 'writeOnly' => true];
            case 'select':
                $vals = array_keys($field['options'] ?? []);
                return $vals === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $vals];
            default:
                return ['type' => 'string'];
        }
    }

    /** @return array<string,mixed> */
    private static function idResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean'], 'id' => ['type' => 'integer']],
        ];
    }

    /** @return array<string,mixed> */
    private static function idParam(): array
    {
        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ];
    }

    /** @return array<string,mixed> */
    private static function jsonResponse(string $desc, array $schema): array
    {
        return ['description' => $desc, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @return array<string,mixed> */
    private static function errorResponse(string $desc): array
    {
        return self::jsonResponse($desc, [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean'], 'error' => ['type' => 'string']],
        ]);
    }

    private static function pascal(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $type)));
    }
}
