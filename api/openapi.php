<?php

/**
 * Serves the generated OpenAPI 3.0 spec for the REST API (Phase 11).
 * Public (the spec is a contract, not data); no auth required.
 */

require_once __DIR__ . '/OpenApiBuilder.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(OpenApiBuilder::build(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
