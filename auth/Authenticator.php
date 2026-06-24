<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../entities/User.php';
require_once __DIR__ . '/../entities/Role.php';
require_once __DIR__ . '/AccessControl.php';

/**
 * Resolves users, roles and effective permissions from the database (Phase 10).
 *
 * Kept free of session/HTTP concerns so it is unit-testable with any driver.
 * Effective permissions = role permissions ∪ the user's own extra permissions.
 * A built-in "admin" role grants "*" even when no roles table row exists, so the
 * very first user is always functional.
 */
class Authenticator
{
    /** Built-in role fallbacks used when no matching row exists. */
    private const BUILTIN_ROLES = [
        'admin' => ['*'],
    ];

    private DbDriver $driver;

    public function __construct(DbDriver $driver)
    {
        $this->driver = $driver;
    }

    /** Whether any user accounts exist (drives the legacy single-password fallback). */
    public function hasUsers(): bool
    {
        return Repositories::users($this->driver)->count() > 0;
    }

    public function findByUsername(string $username): ?User
    {
        $u = Repositories::users($this->driver)->findByName($username);
        return $u instanceof User ? $u : null;
    }

    /**
     * Validate credentials and return the session context, or null on failure.
     *
     * @return array{id:int,name:string,role:string,permissions:array<int,string>}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->findByUsername(trim($username));
        if ($user === null || !$user->enabled() || !$user->verifyPassword($password)) {
            return null;
        }
        return $this->context($user);
    }

    /**
     * Look up a user by API token (Phase 11 REST auth).
     *
     * @return array{id:int,name:string,role:string,permissions:array<int,string>}|null
     */
    public function authenticateToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        foreach (Repositories::users($this->driver)->findAll() as $u) {
            /** @var User $u */
            if ($u->enabled() && hash_equals($u->apiToken(), $token) && $u->apiToken() !== '') {
                return $this->context($u);
            }
        }
        return null;
    }

    /**
     * Resolve a role reference (numeric id or name) to its entity.
     * The role field is stored as an entity id, but a plain name is also
     * accepted so legacy/scripted setups work.
     */
    public function resolveRole(string $role): ?Role
    {
        $role = trim($role);
        if ($role === '') {
            return null;
        }
        $repo = Repositories::roles($this->driver);
        $row = ctype_digit($role) ? $repo->find((int)$role) : $repo->findByName($role);
        return $row instanceof Role ? $row : null;
    }

    /** Effective permissions for a role reference (role row ∪ built-in fallback). @return array<int,string> */
    public function permissionsForRole(string $role): array
    {
        $role = trim($role);
        if ($role === '') {
            return [];
        }
        $row = $this->resolveRole($role);
        $name = $row instanceof Role ? $row->name : $role;
        $perms = self::BUILTIN_ROLES[strtolower($name)] ?? [];
        if ($row instanceof Role) {
            $perms = array_merge($perms, $row->permissions());
        }
        return array_values(array_unique($perms));
    }

    /**
     * Build the immutable session context for a user.
     *
     * @return array{id:int,name:string,role:string,permissions:array<int,string>}
     */
    public function context(User $user): array
    {
        $perms = array_merge(
            $this->permissionsForRole($user->role()),
            $user->extraPermissions()
        );
        $roleRow = $this->resolveRole($user->role());
        return [
            'id' => (int)$user->id,
            'name' => $user->username(),
            'role' => $roleRow instanceof Role ? $roleRow->name : $user->role(),
            'permissions' => array_values(array_unique($perms)),
        ];
    }
}
