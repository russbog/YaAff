<?php

/**
 * Traffic-only isolation for pool domains.
 *
 * Every domain added to the domain pool points at this same docroot, so without
 * a guard the admin panel and the management REST API would be reachable on those
 * domains too. Pool domains exist purely to serve campaign traffic, so any request
 * whose Host is a registered pool domain must be treated as if the admin/management
 * surface does not exist: we answer 404 (never a redirect to login, which would
 * leak the panel's existence).
 *
 * This is the application-level enforcement, included by the admin session gate
 * (admin/securitycheck.php) and the management API entry points (api/rest.php,
 * api/openapi.php). The generated per-domain TLS vhosts add a matching nginx-level
 * deny as defense in depth.
 */

require_once __DIR__ . '/db/db.php';

/** The request host without any port suffix (e.g. "leadbase.shop"). */
function current_request_host(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // IPv6 literals arrive bracketed ("[::1]:80"); leave those intact and only
    // strip a trailing ":port" from plain host names.
    if ($host !== '' && $host[0] !== '[') {
        $colon = strpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }
    }
    return $host;
}

/**
 * 404 + stop the request when the current Host is a registered pool domain.
 * No-op for the admin host (server IP or any non-pool hostname).
 */
function deny_system_path_on_pool_domain(): void
{
    global $db;
    $host = current_request_host();
    if ($host === '') {
        return;
    }
    if ($db->get_domain_by_host($host) !== null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not Found');
    }
}
