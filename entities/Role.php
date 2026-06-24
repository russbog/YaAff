<?php

require_once __DIR__ . '/Entity.php';

/**
 * Access role (Phase 10). A role is a named bundle of permission strings, kept
 * generic and data-driven: adding a permission is editing the list, not code.
 *
 * Settings keys:
 *   permissions  array   granted permission strings (supports wildcards, see
 *                        {@see AccessControl}). ["*"] = full access.
 *   description  string  free-form note
 */
class Role extends Entity
{
    public const TABLE = 'roles';

    /** @return array<int,string> */
    public function permissions(): array
    {
        $p = $this->get('permissions', []);
        if (is_string($p)) {
            $p = array_filter(array_map('trim', explode(',', $p)));
        }
        if (!is_array($p)) {
            return [];
        }
        return array_values(array_map('strval', $p));
    }

    public function description(): string
    {
        return (string)$this->get('description', '');
    }
}
