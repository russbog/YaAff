<?php
require_once __DIR__ . '/../debug.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/../redirect.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../domainguard.php';

// Pool domains are traffic-only: the admin panel must not exist on them.
deny_system_path_on_pool_domain();

global $cloSettings;
$admDomain = $cloSettings['adminDomain'];
if (isset($admDomain) && !empty($admDomain)) {
    $currentDomain = $_SERVER['SERVER_NAME'] ?? '';
    if ($currentDomain !== $admDomain) {
        add_log('warning', "Tried to access admin page from $currentDomain, but admin  Domain $admDomain is set. User not allowed to access this page!");
        if ($cloSettings['debug'] === true) {
            echo "Admin Domain $admDomain is set, but your domain is $currentDomain. You are not allowed to access this page! ";
        } else {
            http_response_code(404);
        }
        die();
    }
}
if (!check_password(false)) {
    $loginPath = get_cloaker_path() . "login.php";
    if (!str_contains($loginPath, $_SERVER['PHP_SELF'])) {
        redirect($loginPath);
        exit();
    }
}