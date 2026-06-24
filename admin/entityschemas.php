<?php

/**
 * Declarative field schemas for the generic entity admin (Phase 1).
 *
 * One schema per entity type drives BOTH the client-side form (built in
 * js/entityadmin.js) and server-side persistence (entityapi.php). Adding a
 * field is a data change here only — no new code. Every field except the core
 * "name"/"group" is stored inside the entity's schemaless settings JSON bag.
 *
 * Field shape:
 *   key     string   settings key (or "name" / "group" for core columns)
 *   label   string   form label
 *   type    string   text|number|textarea|select|checkbox|csv|kvlines|json|entityref
 *   options array    [value => label] for select
 *   entity  string   target entity type for entityref
 *   help    string   helper text under the field
 *   default mixed    default value for new entities
 */

function entity_schemas(): array
{
    static $schemas = null;
    if ($schemas !== null) {
        return $schemas;
    }

    $redirectTypes = [
        'http_301'       => 'HTTP 301 redirect',
        'http_302'       => 'HTTP 302 redirect',
        'http_307'       => 'HTTP 307 redirect',
        'http_404'       => 'HTTP 404 (not found)',
        'js'             => 'JS redirect',
        'meta'           => 'Meta refresh',
        'double_meta'    => 'Double meta refresh (drop referrer)',
        'blank_referrer' => 'Blank referrer redirect',
        'formsubmit'     => 'Form submit (POST)',
        'iframe'         => 'iframe',
        'curl'           => 'cURL proxy (no redirect)',
        'remote'         => 'Remote reverse-proxy',
        'inline'         => 'Inline content',
        'custom_json'    => 'Custom JSON (configurable Content-Type)',
    ];

    $schemas = [
        'networks' => [
            'title' => 'Networks',
            'singular' => 'Network',
            'icon' => 'bi-diagram-3',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text', 'help' => 'Optional folder; created automatically.'],
                ['key' => 'currency', 'label' => 'Default currency', 'type' => 'text', 'default' => 'USD'],
                ['key' => 'postback_url', 'label' => 'Incoming postback URL', 'type' => 'text', 'help' => 'Template you hand to the network, e.g. https://t.dom/postback.php?clickid={subid}&status={status}&payout={payout}'],
                ['key' => 'status_map', 'label' => 'Status mapping', 'type' => 'kvlines', 'help' => 'One per line: external=internal. Internal: lead, sale, rejected, hold. Example: approved=sale'],
                ['key' => 'offer_param', 'label' => 'Offer URL template', 'type' => 'text', 'help' => 'Optional template used when building offer URLs for this network.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'sources' => [
            'title' => 'Traffic Sources',
            'singular' => 'Source',
            'icon' => 'bi-broadcast',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'param_map', 'label' => 'Parameter mapping', 'type' => 'json', 'help' => 'JSON list of {"alias":"sub1","token":"sub_id_1","macro":"{{campaign.id}}"}'],
                ['key' => 'postback_url', 'label' => 'Outgoing S2S postback URL', 'type' => 'text', 'help' => 'Sent back to the source on conversion. Tokens allowed.'],
                ['key' => 'postback_statuses', 'label' => 'Postback statuses', 'type' => 'csv', 'help' => 'Comma-separated internal statuses that fire the postback, e.g. lead,sale'],
                ['key' => 'cost_param', 'label' => 'Cost query param', 'type' => 'text', 'help' => 'Incoming param carrying click cost.'],
                ['key' => 'cost_currency', 'label' => 'Cost currency', 'type' => 'text', 'default' => 'USD'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'offers' => [
            'title' => 'Offers',
            'singular' => 'Offer',
            'icon' => 'bi-bullseye',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'network_id', 'label' => 'Network', 'type' => 'entityref', 'entity' => 'networks'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['redirect' => 'Redirect (remote URL)', 'local' => 'Local landing'], 'default' => 'redirect'],
                ['key' => 'url', 'label' => 'Target URL', 'type' => 'text', 'help' => 'For redirect offers. Tokens allowed, e.g. https://offer.com/?clickid={subid}'],
                ['key' => 'redirect_type', 'label' => 'Redirect type', 'type' => 'select', 'options' => $redirectTypes, 'default' => 'http_302'],
                ['key' => 'payout', 'label' => 'Payout', 'type' => 'number', 'default' => 0],
                ['key' => 'payout_type', 'label' => 'Payout type', 'type' => 'select', 'options' => ['cpa' => 'CPA', 'cpc' => 'CPC', 'cpl' => 'CPL', 'revshare' => 'RevShare'], 'default' => 'cpa'],
                ['key' => 'payout_param', 'label' => 'Dynamic payout param', 'type' => 'text', 'help' => 'Optional query param to read payout from at conversion time.'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'text', 'default' => 'USD'],
                ['key' => 'geo', 'label' => 'Geo', 'type' => 'text', 'help' => 'Free-form geo note, e.g. US, CA.'],
                ['key' => 'cap_daily', 'label' => 'Daily cap', 'type' => 'number', 'default' => 0, 'help' => '0 = unlimited'],
                ['key' => 'cap_total', 'label' => 'Total cap', 'type' => 'number', 'default' => 0, 'help' => '0 = unlimited'],
                ['key' => 'multi_values', 'label' => 'Extra token values', 'type' => 'kvlines', 'help' => 'One per line: name=value. Exposed as {offer_value:name}.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'landings' => [
            'title' => 'Landings',
            'singular' => 'Landing',
            'icon' => 'bi-file-earmark-richtext',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['local' => 'Local (uploaded folder)', 'remote' => 'Remote URL'], 'default' => 'local'],
                ['key' => 'path', 'label' => 'Local folder', 'type' => 'text', 'help' => 'Folder name of an uploaded landing (local type).'],
                ['key' => 'url', 'label' => 'Remote URL', 'type' => 'text', 'help' => 'For remote landings. Tokens allowed.'],
                ['key' => 'redirect_type', 'label' => 'Redirect type', 'type' => 'select', 'options' => $redirectTypes, 'default' => 'http_302'],
                ['key' => 'protect', 'label' => 'Bot protection / cloak', 'type' => 'checkbox', 'default' => false],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'integrations' => [
            'title' => 'Conversion APIs',
            'singular' => 'Integration',
            'icon' => 'bi-cloud-upload',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'type', 'label' => 'Preset type', 'type' => 'select', 'options' => ['generic' => 'Generic HTTP', 'fb_capi' => 'Facebook CAPI', 'google' => 'Google / GA4', 'tiktok' => 'TikTok'], 'default' => 'generic'],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'checkbox', 'default' => true],
                ['key' => 'method', 'label' => 'HTTP method', 'type' => 'select', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'default' => 'POST'],
                ['key' => 'url', 'label' => 'Endpoint URL', 'type' => 'text', 'help' => 'Tokens allowed: {clickid} {status} {payout} {currency} {time} {ip} {ua} {c.PARAM} {sub_id_N}'],
                ['key' => 'content_type', 'label' => 'Content-Type', 'type' => 'text', 'default' => 'application/json'],
                ['key' => 'headers', 'label' => 'Headers', 'type' => 'kvlines', 'help' => 'One per line: Header-Name=value (tokens allowed).'],
                ['key' => 'body', 'label' => 'Body template', 'type' => 'textarea', 'help' => 'Raw request body with {token} placeholders (JSON or form-encoded).'],
                ['key' => 'statuses', 'label' => 'Fire on statuses', 'type' => 'csv', 'help' => 'Comma-separated internal statuses, e.g. Lead,Purchase. Empty = all.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
    ];

    return $schemas;
}

/** @return array<string,mixed>|null Schema for one entity type, or null. */
function entity_schema(string $type): ?array
{
    return entity_schemas()[$type] ?? null;
}
