<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/../auth/Authenticator.php';

/**
 * Bearer-token authentication for the REST API (Phase 11).
 *
 * Two token sources, both yielding the immutable context array used elsewhere
 * ({@see Authenticator::context}):
 *   1. a master token from settings ("apiToken") → super-admin (perms ["*"]),
 *      mirroring the legacy single-password model for machine access;
 *   2. a per-user api_token stored on a User entity → that user's permissions.
 *
 * No session/HTTP concerns, so it is unit-testable with any driver.
 */
class ApiAuth
{
    /**
     * Resolve a bearer token to a permission context, or null when invalid.
     *
     * @return array{id:int,name:string,role:string,permissions:array<int,string>}|null
     */
    public static function resolve(DbDriver $driver, string $bearer, string $masterToken = ''): ?array
    {
        $bearer = trim($bearer);
        if ($bearer === '') {
            return null;
        }
        if ($masterToken !== '' && hash_equals($masterToken, $bearer)) {
            return ['id' => 0, 'name' => 'api', 'role' => 'admin', 'permissions' => ['*']];
        }
        return (new Authenticator($driver))->authenticateToken($bearer);
    }

    /** Extract the bearer token from the request headers (or ?token= fallback). */
    public static function bearerFromRequest(): string
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string)$_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return trim((string)($_GET['token'] ?? ''));
    }
}
