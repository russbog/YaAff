<?php

/**
 * Serves the generated OpenAPI 3.0 spec for the REST API (Phase 11).
 * Public (the spec is a contract, not data); no auth required.
 */

require_once __DIR__ . '/../domainguard.php';
require_once __DIR__ . '/OpenApiBuilder.php';

// The OpenAPI spec describes the system API — hide it on pool domains.
deny_system_path_on_pool_domain();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(OpenApiBuilder::build(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
