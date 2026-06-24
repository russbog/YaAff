<?php

require_once __DIR__ . '/Authenticator.php';
require_once __DIR__ . '/AccessControl.php';
require_once __DIR__ . '/../cookies.php';

/**
 * Session-level authentication & authorization helpers (Phase 10).
 *
 * Backward compatible by design: when no user accounts exist the tracker keeps
 * its original single shared-password login and every authenticated request is
 * treated as a super-admin. Once users are created, login requires a username
 * and permissions are enforced from the user's role.
 */

function auth_authenticator(): Authenticator
{
    require_once __DIR__ . '/../db/db.php';
    global $db;
    return new Authenticator($db->driver());
}

/** Whether multi-user mode is active (at least one account exists). */
function auth_multiuser(): bool
{
    try {
        return auth_authenticator()->hasUsers();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Current logged-in user context, or null in legacy single-password mode.
 *
 * @return array{id:int,name:string,role:string,permissions:array<int,string>}|null
 */
function auth_current_user(): ?array
{
    get_session(true);
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

/**
 * Validate a username/password and start an authenticated session.
 */
function auth_attempt(string $username, string $password): bool
{
    $ctx = auth_authenticator()->authenticate($username, $password);
    if ($ctx === null) {
        return false;
    }
    get_session();
    $_SESSION['loggedin'] = true;
    $_SESSION['user'] = $ctx;
    session_write_close();
    return true;
}

function auth_logout(): void
{
    get_session();
    unset($_SESSION['user']);
    $_SESSION['loggedin'] = false;
    session_write_close();
}

/**
 * Whether the current session may perform an action. In legacy mode (no user
 * context) the authenticated admin is a super-admin and everything is allowed.
 */
function auth_can(string $permission): bool
{
    $u = auth_current_user();
    if ($u === null) {
        return true;
    }
    return AccessControl::permits($u['permissions'], $permission);
}

/**
 * Enforce a permission for the current request; emit 403 (or JSON) and stop
 * when the user is not allowed. No-op in legacy single-password mode.
 */
function auth_require(string $permission, bool $json = false): void
{
    if (auth_can($permission)) {
        return;
    }
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden: missing permission ' . $permission]);
    } else {
        echo 'Forbidden: you do not have permission to ' . htmlspecialchars($permission);
    }
    exit();
}
