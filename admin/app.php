<?php

/**
 * Host page for the YaAff modern admin SPA (React + Vite build).
 *
 * Enforces the same session gate as every other admin page, then serves the
 * compiled SPA shell from admin/app/. A <base> tag and a small bootstrap global
 * are injected so the client resolves both its hashed assets and the JSON API
 * (admin/spa.php, admin/entityapi.php, …) regardless of the install sub-path.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../paths.php';

$dist = __DIR__ . '/app/index.html';
if (!is_readable($dist)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>YaAff</title>'
        . '<body style="font-family:system-ui;background:#0b1220;color:#e2e8f0;padding:40px">'
        . '<h1>YaAff UI not built</h1>'
        . '<p>The modern admin bundle is missing. Run <code>npm install &amp;&amp; npm run build</code> '
        . 'inside <code>admin-ui/</code> to generate <code>admin/app/</code>.</p>';
    exit;
}

$adminBase = get_cloaker_path(); // e.g. https://host/admin/
$appBase = $adminBase . 'app/';
$html = (string)file_get_contents($dist);

$inject = '<base href="' . htmlspecialchars($appBase, ENT_QUOTES) . '">'
    . '<script>window.__YAAFF__=' . json_encode([
        'apiBase' => $adminBase,
        'version' => trim(@file_get_contents(__DIR__ . '/version.txt') ?: ''),
    ], JSON_UNESCAPED_SLASHES) . ';</script>';

$html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $inject, $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
