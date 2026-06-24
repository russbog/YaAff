<?php

require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../macros.php';
require_once __DIR__ . '/../requestfunc.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../integrations/ConversionApiSender.php';

/**
 * Shared conversion pipeline used by both the S2S postback endpoint and the JS
 * pixel endpoint. Enforces dedup, records the conversion, keeps the legacy
 * click status in sync (backward compat), fires outgoing S2S postbacks and
 * Conversion-API integrations, and writes the postback audit log.
 *
 * @param array<string,mixed> $click   click row
 * @param array<string,mixed> $request raw request params (exposed as tokens)
 * @return array{ok:bool,duplicate:bool,message:string}
 */
function register_conversion(Db $db, Campaign $c, array $click, string $inner_status, float $payout, float $revenue, string $currency, string $tid, array $request, string $source): array
{
    $clickid = (string)($click['clickid'] ?? '');
    $campId = (int)($click['campaign_id'] ?? 0);

    $dedupKey = $c->postback->buildDedupKey($clickid, $tid);
    if ($db->conversion_exists($campId, $dedupKey)) {
        $msg = "Duplicate conversion for clickid $clickid (dedup: $dedupKey) ignored.";
        $db->log_postback('in', $clickid, $inner_status, $payout, $currency, $source, 200, $msg);
        return ['ok' => false, 'duplicate' => true, 'message' => $msg];
    }

    $db->add_conversion($campId, $clickid, $tid, $inner_status, $payout, $revenue, $currency, $source, $dedupKey, $request);
    $db->update_status($clickid, $inner_status, $payout);

    $msg = "Conversion for clickid $clickid with status $inner_status and payout $payout $currency accepted.";
    $db->log_postback('in', $clickid, $inner_status, $payout, $currency, $source, 200, $msg);

    process_s2s_posbacks($db, $c->postback->s2sPostbacks, $inner_status, $click);
    fire_conversion_integrations($db, $c->postback->integrationIds, $inner_status, $click, $payout, $revenue, $currency, $request);

    return ['ok' => true, 'duplicate' => false, 'message' => $msg];
}

/**
 * Fire every configured Conversion-API integration whose status filter matches.
 * External failures never throw; each attempt is recorded in the postback log.
 *
 * @param int[]               $integrationIds
 * @param array<string,mixed> $click
 * @param array<string,mixed> $request
 */
function fire_conversion_integrations(Db $db, array $integrationIds, string $inner_status, array $click, float $payout, float $revenue, string $currency, array $request): void
{
    if ($integrationIds === []) {
        return;
    }
    $repo = Repositories::integrations($db->driver());
    $tokens = ConversionApiSender::buildTokens($click, $inner_status, $payout, $revenue, $currency, $request);
    $clickid = (string)($click['clickid'] ?? '');

    foreach ($integrationIds as $id) {
        $integration = $repo->find((int)$id);
        if (!$integration instanceof Integration) {
            continue;
        }
        if (!$integration->firesFor($inner_status)) {
            continue;
        }
        $res = ConversionApiSender::send($integration, $tokens);
        $message = $integration->name . ' -> ' . ($res['error'] !== '' ? ('ERROR: ' . $res['error']) : substr($res['content'], 0, 500));
        $db->log_postback('out', $clickid, $inner_status, $payout, $currency, $res['url'], $res['http_code'], $message);
        add_log('postback', 'capi ' . $integration->name . ' ' . $res['http_code'] . ' ' . $res['error']);
    }
}

/**
 * Send outgoing S2S postbacks to traffic sources for the matched status.
 *
 * @param array<int,S2sPostback> $s2s_postbacks
 * @param array<string,mixed>    $click
 */
function process_s2s_posbacks(Db $db, array $s2s_postbacks, string $inner_status, array $click): void
{
    $clickid = (string)($click['clickid'] ?? '');
    $userid = (string)($click['userid'] ?? '');
    $mp = new MacrosProcessor(null, $click, $clickid, $userid);
    foreach ($s2s_postbacks as $s2s) {
        if (empty($s2s->url)) {
            continue;
        }
        if (!in_array($inner_status, $s2s->events, true)) {
            continue;
        }
        $final_url = str_replace('{status}', $inner_status, $s2s->url);
        $final_url = $mp->replace_url_macros($final_url);
        $s2s_res = ['info' => ['http_code' => 0]];
        switch ($s2s->method) {
            case 'GET':
                $s2s_res = get($final_url);
                break;
            case 'POST':
                $urlParts = explode('?', $final_url);
                $params = [];
                if (count($urlParts) > 1) {
                    parse_str($urlParts[1], $params);
                }
                $s2s_res = post($urlParts[0], $params);
                break;
        }
        $code = (int)($s2s_res['info']['http_code'] ?? 0);
        add_log('postback', $s2s->method . ', ' . $final_url . ', ' . $inner_status . ', ' . $code);
        $db->log_postback('out', $clickid, $inner_status, 0, '', $final_url, $code, 'S2S ' . $s2s->method);
    }
}
