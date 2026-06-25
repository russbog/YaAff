<?php

require_once __DIR__ . '/debug.php';
DebugMethods::start("YWBMainCycle");

//we always need a slash at the end of the url, otherwise links will not work properly
$url = $_SERVER['REQUEST_URI'];
if (str_ends_with($url, '/admin')) {
    header("Location: " . $url . "/");
    exit();
}

require_once __DIR__ . '/settings.php';

//handle robots.txt requests — per-domain indexing toggle (domain pool).
//A domain marked "index allowed" serves a permissive robots.txt; otherwise the
//default is to disallow all crawling (unchanged behaviour for unknown domains).
if (isset($_SERVER['REQUEST_URI']) && str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/robots.txt')) {
    require_once __DIR__ . '/db/db.php';
    global $db;
    $indexAllowed = $db->domain_index_allowed((string)($_SERVER['HTTP_HOST'] ?? ''));
    header('Content-Type: text/plain');
    echo $indexAllowed ? "User-agent: *\nDisallow:\n" : "User-agent: *\nDisallow: /\n";
    exit();
}

require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/directload.php';

//fix for Apache Multiviews and/or PHP Development Server
if ($_SERVER['SCRIPT_NAME'] !== $_SERVER['PHP_SELF']) {
    http_response_code(404);
    exit("Not Found");
}

require_once __DIR__ . '/tds.php';
require_once __DIR__ . '/redirect.php';

$action = Tds::getAction();
if ($action->action !== 'redirect') {
    DebugMethods::stop("YWBMainCycle");
    $action->perform();
} else {
    $action->perform();
    DebugMethods::stop("YWBMainCycle");
}
