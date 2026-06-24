<?php

require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../currency.php';
require_once __DIR__ . '/conversion_handler.php';
global $db;

/**
 * JS / image conversion pixel. Tracks conversions fired from a landing page or
 * thank-you page. Always returns a 1x1 transparent GIF (or JSON when
 * format=json) so it can be embedded as <img> or fetched via JS without ever
 * breaking the host page, regardless of outcome.
 */

function pixel_respond(bool $ok, string $message): void
{
    $format = strtolower($_REQUEST['format'] ?? 'gif');
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        return;
    }
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    // 1x1 transparent GIF
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

$clickid = $_REQUEST['clickid'] ?? '';
if ($clickid === '') {
    add_log('postback', 'Pixel: no clickid. Url: ' . ($_SERVER['REQUEST_URI'] ?? ''));
    pixel_respond(false, 'no clickid');
    exit;
}

$click = $db->get_click_by_clickid($clickid);
if (empty($click)) {
    add_log('postback', 'Pixel: unknown clickid ' . $clickid);
    pixel_respond(false, 'unknown clickid');
    exit;
}

$cs = $db->get_campaign_settings($click['campaign_id']);
$c = new Campaign($click['campaign_id'], $cs);

$statusParam = (string)($_REQUEST['status'] ?? '');
$internal = ['lead' => 'Lead', 'purchase' => 'Purchase', 'sale' => 'Purchase', 'reject' => 'Reject', 'trash' => 'Trash'];
$inner_status = match (strtolower($statusParam)) {
    '' => 'Lead',
    strtolower($c->postback->leadStatusName) => 'Lead',
    strtolower($c->postback->purchaseStatusName) => 'Purchase',
    strtolower($c->postback->rejectStatusName) => 'Reject',
    strtolower($c->postback->trashStatusName) => 'Trash',
    default => $internal[strtolower($statusParam)] ?? ''
};
if ($inner_status === '') {
    add_log('postback', 'Pixel: unknown status ' . $statusParam . ' for clickid ' . $clickid);
    pixel_respond(false, 'unknown status');
    exit;
}

$currency = strtoupper($_REQUEST['currency'] ?? 'USD');
$payout = is_numeric($_REQUEST['payout'] ?? null) ? CurrencyConverter::convert($_REQUEST['payout'], $currency) : 0.0;
$revenue = is_numeric($_REQUEST['revenue'] ?? null) ? CurrencyConverter::convert($_REQUEST['revenue'], $currency) : (float)$payout;
$tid = (string)($_REQUEST['tid'] ?? $_REQUEST['transaction_id'] ?? '');

$source = 'pixel:' . ($_SERVER['REMOTE_ADDR'] ?? '');
$result = register_conversion($db, $c, $click, $inner_status, (float)$payout, (float)$revenue, $currency, $tid, $_REQUEST, $source);

add_log('postback', $result['message']);
pixel_respond($result['ok'], $result['message']);
