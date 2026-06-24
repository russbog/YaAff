<?php

require_once __DIR__ . '/Entity.php';

/**
 * Admin user (Phase 10). The login name is the entity `name`; everything else
 * lives in the schemaless settings bag, following the first-class entity
 * convention. Passwords are stored only as a hash; the plaintext never touches
 * the database.
 *
 * Settings keys:
 *   password     string  password hash (password_hash); never returned to UI
 *   role         string  role name resolved to permissions at login
 *   enabled      bool    master on/off switch (default true)
 *   api_token    string  bearer token for the REST API (Phase 11)
 *   permissions  array   optional extra permissions merged with the role's
 */
class User extends Entity
{
    public const TABLE = 'users';

    public function username(): string
    {
        return $this->name;
    }

    public function passwordHash(): string
    {
        return (string)$this->get('password', '');
    }

    public function enabled(): bool
    {
        return (bool)$this->get('enabled', true);
    }

    public function role(): string
    {
        return (string)$this->get('role', '');
    }

    public function apiToken(): string
    {
        return (string)$this->get('api_token', '');
    }

    /** Extra per-user permissions, merged on top of the role's. @return array<int,string> */
    public function extraPermissions(): array
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

    public function setPassword(string $plain): static
    {
        return $this->set('password', password_hash($plain, PASSWORD_DEFAULT));
    }

    public function verifyPassword(string $plain): bool
    {
        $hash = $this->passwordHash();
        return $hash !== '' && password_verify($plain, $hash);
    }
}
