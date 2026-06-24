<?php

require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../requestfunc.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../currency.php';
require_once __DIR__ . '/conversion_handler.php';
global $db;

$curLink = (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$clickid = $_REQUEST['clickid'] ?? '';
if ($clickid === '') {
    http_response_code(500);
    $msg = 'No clickid found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$status = $_REQUEST['status'] ?? '';
if ($status === '') {
    http_response_code(500);
    $msg = 'No status found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$payout = $_REQUEST['payout'] ?? '';
if ($payout === '') {
    http_response_code(500);
    $msg = 'No payout found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}

$click = $db->get_click_by_clickid($clickid);
if (empty($click)) {
    http_response_code(500);
    $msg = 'No click data for clickid ' . $clickid . ' found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$cs = $db->get_campaign_settings($click['campaign_id']);
$c = new Campaign($click['campaign_id'], $cs);

$inner_status = match (strtolower($status)) {
    strtolower($c->postback->leadStatusName) => 'Lead',
    strtolower($c->postback->purchaseStatusName) => 'Purchase',
    strtolower($c->postback->rejectStatusName) => 'Reject',
    strtolower($c->postback->trashStatusName) => 'Trash',
    default => ''
};

if ($inner_status === '') {
    http_response_code(500);
    $msg = 'Status ' . $status . ' is unknown! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}

$currency = strtoupper($_REQUEST['currency'] ?? 'USD');
$payout = CurrencyConverter::convert($payout, $currency);
$revenue = isset($_REQUEST['revenue']) && is_numeric($_REQUEST['revenue'])
    ? CurrencyConverter::convert($_REQUEST['revenue'], $currency)
    : (float)$payout;
$tid = (string)($_REQUEST['tid'] ?? $_REQUEST['transaction_id'] ?? '');

$source = 'postback:' . ($_SERVER['REMOTE_ADDR'] ?? '');
$result = register_conversion($db, $c, $click, $inner_status, (float)$payout, (float)$revenue, $currency, $tid, $_REQUEST, $source);

http_response_code(200);
add_log('postback', $result['message']);
echo $result['message'];
