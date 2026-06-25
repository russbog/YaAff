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

    $currencyOptions = currency_options();
    // Tokens substituted live in offer / landing destination URLs (shared TokenRegistry vocabulary).
    // Any param passed to the campaign link is also available by its bare name, e.g. {utm_term}.
    $urlTokens = ['{clickid}', '{sub_id_1}', '{c.utm_source}', '{country}', '{device}', '{os}', '{userid}'];
    // Tokens substituted live in outgoing S2S postbacks and Conversion API templates.
    $postbackTokens = ['{clickid}', '{status}', '{payout}', '{revenue}', '{currency}', '{sub_id_1}', '{c.utm_source}', '{country}'];

    $schemas = [
        'networks' => [
            'title' => 'Networks',
            'singular' => 'Network',
            'icon' => 'bi-diagram-3',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text', 'help' => 'Optional folder; created automatically.'],
                ['key' => 'currency', 'label' => 'Default currency', 'type' => 'select', 'options' => $currencyOptions, 'default' => 'USD', 'help' => 'Currency payouts arrive in; converted to your reporting currency.'],
                ['key' => 'postback_url', 'label' => 'Incoming postback URL', 'type' => 'text', 'section' => 'Postback integration', 'placeholder' => 'https://your-domain/api/postback.php?clickid=REPLACE&status=REPLACE&payout=REPLACE', 'help' => 'Template you hand to the network. The network must call back with clickid (the {subid} you passed them), status and payout. Optional: currency, revenue, tid.'],
                ['key' => 'status_map', 'label' => 'Status mapping', 'type' => 'kvlines', 'section' => 'Postback integration', 'help' => 'One per line: external=internal. Internal: lead, sale, rejected, hold. Example: approved=sale'],
                ['key' => 'offer_param', 'label' => 'Offer URL template', 'type' => 'text', 'section' => 'Postback integration', 'help' => 'Optional template used when building offer URLs for this network. Any param passed to the campaign link is referenceable by name (e.g. {your_param}); unknown tokens are dropped to empty.', 'tokens' => $urlTokens],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
            ],
        ],
        'sources' => [
            'title' => 'Traffic Sources',
            'singular' => 'Source',
            'icon' => 'bi-broadcast',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'param_map', 'label' => 'Parameter mapping', 'type' => 'json', 'section' => 'Parameters', 'help' => 'JSON list of {"alias":"sub1","token":"sub_id_1","macro":"{{campaign.id}}"}'],
                ['key' => 'cost_param', 'label' => 'Cost query param', 'type' => 'text', 'section' => 'Cost tracking', 'help' => 'Incoming param carrying click cost, e.g. cost or price.'],
                ['key' => 'cost_currency', 'label' => 'Cost currency', 'type' => 'select', 'options' => $currencyOptions, 'default' => 'USD', 'section' => 'Cost tracking', 'help' => 'Currency of the incoming cost value; converted to your reporting currency.'],
                ['key' => 'postback_url', 'label' => 'Outgoing S2S postback URL', 'type' => 'text', 'section' => 'Postback', 'placeholder' => 'https://source.com/postback?cid={clickid}&status={status}&payout={payout}', 'help' => 'Fired back to the source on conversion. Tokens below are substituted live.', 'tokens' => $postbackTokens],
                ['key' => 'postback_statuses', 'label' => 'Postback statuses', 'type' => 'csv', 'section' => 'Postback', 'help' => 'Comma-separated internal statuses that fire the postback, e.g. lead,sale'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
            ],
        ],
        'offers' => [
            'title' => 'Offers',
            'singular' => 'Offer',
            'icon' => 'bi-bullseye',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'network_id', 'label' => 'Network', 'type' => 'entityref', 'entity' => 'networks', 'help' => 'Links payout currency and postback handling.'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['redirect' => 'Redirect (remote URL)', 'local' => 'Local landing'], 'default' => 'redirect'],
                ['key' => 'geo', 'label' => 'Geo', 'type' => 'text', 'help' => 'Free-form geo note, e.g. US, CA.'],
                ['key' => 'url', 'label' => 'Target URL', 'type' => 'text', 'section' => 'Destination', 'showIf' => ['field' => 'type', 'in' => ['redirect']], 'placeholder' => 'https://offer.com/{some_param}?clickid={clickid}', 'help' => 'Where the click is sent. Tokens (path and query) are substituted live: any param you pass to the campaign link is available by name (e.g. {some_param}), and unknown tokens are dropped to empty.', 'tokens' => $urlTokens],
                ['key' => 'redirect_type', 'label' => 'Redirect type', 'type' => 'select', 'options' => $redirectTypes, 'default' => 'http_302', 'section' => 'Destination', 'showIf' => ['field' => 'type', 'in' => ['redirect']]],
                ['key' => 'payout', 'label' => 'Payout', 'type' => 'number', 'default' => 0, 'section' => 'Payout'],
                ['key' => 'payout_type', 'label' => 'Payout type', 'type' => 'select', 'options' => ['cpa' => 'CPA — per action', 'cpc' => 'CPC — per click', 'cpl' => 'CPL — per lead', 'revshare' => 'RevShare — % of revenue'], 'default' => 'cpa', 'section' => 'Payout'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'select', 'options' => $currencyOptions, 'default' => 'USD', 'section' => 'Payout'],
                ['key' => 'payout_param', 'label' => 'Dynamic payout param', 'type' => 'text', 'section' => 'Payout', 'help' => 'Optional query param to read payout from at conversion time (overrides the fixed value).'],
                ['key' => 'cap_daily', 'label' => 'Daily cap', 'type' => 'number', 'default' => 0, 'section' => 'Caps & limits', 'help' => 'Max conversions per day. 0 = unlimited.'],
                ['key' => 'cap_total', 'label' => 'Total cap', 'type' => 'number', 'default' => 0, 'section' => 'Caps & limits', 'help' => 'Max conversions lifetime. 0 = unlimited.'],
                ['key' => 'multi_values', 'label' => 'Extra token values', 'type' => 'kvlines', 'section' => 'Advanced', 'help' => 'One per line: name=value. Exposed as {offer_value:name}.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
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
                ['key' => 'path', 'label' => 'Local folder', 'type' => 'text', 'section' => 'Source', 'showIf' => ['field' => 'type', 'in' => ['local']], 'help' => 'Folder name of an uploaded landing. Upload a ZIP from the Landings toolbar, then pick its folder here.'],
                ['key' => 'url', 'label' => 'Remote URL', 'type' => 'text', 'section' => 'Source', 'showIf' => ['field' => 'type', 'in' => ['remote']], 'placeholder' => 'https://landing.com/?clickid={clickid}', 'help' => 'Where the visitor is sent for a remote landing. Tokens below are substituted live.', 'tokens' => $urlTokens],
                ['key' => 'redirect_type', 'label' => 'Redirect type', 'type' => 'select', 'options' => $redirectTypes, 'default' => 'http_302', 'section' => 'Source', 'showIf' => ['field' => 'type', 'in' => ['remote']]],
                ['key' => 'protect', 'label' => 'Bot protection / cloak', 'type' => 'checkbox', 'default' => false, 'section' => 'Protection', 'help' => 'Route detected bots to the safe page instead of this landing.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
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
                ['key' => 'method', 'label' => 'HTTP method', 'type' => 'select', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'default' => 'POST', 'section' => 'Request'],
                ['key' => 'url', 'label' => 'Endpoint URL', 'type' => 'text', 'section' => 'Request', 'help' => 'Where conversions are posted. Tokens below are substituted live.', 'tokens' => $postbackTokens],
                ['key' => 'content_type', 'label' => 'Content-Type', 'type' => 'text', 'default' => 'application/json', 'section' => 'Request'],
                ['key' => 'headers', 'label' => 'Headers', 'type' => 'kvlines', 'section' => 'Request', 'help' => 'One per line: Header-Name=value (tokens allowed).'],
                ['key' => 'body', 'label' => 'Body template', 'type' => 'textarea', 'section' => 'Request', 'placeholder' => '{"event":"purchase","value":{payout},"currency":"{currency}","click_id":"{clickid}"}', 'help' => 'Raw request body with {token} placeholders (JSON or form-encoded). Use the tokens above.'],
                ['key' => 'statuses', 'label' => 'Fire on statuses', 'type' => 'csv', 'section' => 'Trigger', 'help' => 'Comma-separated internal statuses, e.g. Lead,Purchase. Empty = all.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
            ],
        ],
        'domains' => [
            'title' => 'Domains',
            'singular' => 'Domain',
            'icon' => 'bi-globe2',
            'fields' => [
                ['key' => 'name', 'label' => 'Hostname', 'type' => 'text', 'required' => true, 'help' => 'No scheme. Separate several domains with commas. Use *.example.com to match all subdomains.'],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'index_allowed', 'label' => 'Allow indexing', 'type' => 'checkbox', 'default' => false, 'help' => 'When on, robots.txt allows crawlers. Off (default) disallows all indexing.'],
                ['key' => 'campaign_id', 'label' => 'Default campaign (index page)', 'type' => 'number', 'help' => 'Campaign served at the domain root. Paths with a campaign identifier still work as usual.'],
                ['key' => 'intercept_404', 'label' => 'Intercept 404', 'type' => 'checkbox', 'default' => false, 'help' => 'When on, unmatched paths fall back to the default campaign; otherwise a 404 stub is shown.'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['regular' => 'Regular', 'wildcard' => 'Wildcard', 'alias' => 'Alias'], 'default' => 'regular'],
                ['key' => 'alias_of', 'label' => 'Alias of', 'type' => 'text', 'help' => 'Canonical host this alias resolves to (alias type only).'],
                ['key' => 'dns_type', 'label' => 'DNS record type', 'type' => 'select', 'options' => ['A' => 'A', 'CNAME' => 'CNAME', 'AAAA' => 'AAAA', 'TXT' => 'TXT'], 'default' => 'A'],
                ['key' => 'dns_content', 'label' => 'DNS record value', 'type' => 'text', 'help' => 'IP for A/AAAA, target host for CNAME.'],
                ['key' => 'dns_proxied', 'label' => 'Cloudflare proxied (orange cloud)', 'type' => 'checkbox', 'default' => false],
                ['key' => 'cf_zone_id', 'label' => 'Cloudflare zone id', 'type' => 'text', 'help' => 'Used to create the DNS record via the Cloudflare API.'],
                ['key' => 'cf_api_token', 'label' => 'Cloudflare API token', 'type' => 'text', 'help' => 'Scoped token (Zone:DNS:Edit). Stored per domain; never logged.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'rules' => [
            'title' => 'Automation Rules',
            'singular' => 'Rule',
            'icon' => 'bi-robot',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'checkbox', 'default' => true],
                ['key' => 'schedule', 'label' => 'Schedule', 'type' => 'text', 'default' => '@hourly', 'help' => 'Interval (300, every:5m, every:2h), cron (m h dom mon dow), macro (@hourly, @daily) or empty (every run).'],
                ['key' => 'timezone', 'label' => 'Timezone', 'type' => 'text', 'default' => 'UTC', 'help' => 'IANA tz used for cron schedules and the metric window.'],
                ['key' => 'campaign_id', 'label' => 'Campaign', 'type' => 'number', 'default' => 0, 'help' => 'Metric scope. 0 = all campaigns.'],
                ['key' => 'window', 'label' => 'Metric window', 'type' => 'select', 'options' => ['today' => 'Today', 'yesterday' => 'Yesterday', '1h' => 'Last 1h', '24h' => 'Last 24h', '7d' => 'Last 7d', '30d' => 'Last 30d'], 'default' => 'today'],
                ['key' => 'match', 'label' => 'Match', 'type' => 'select', 'options' => ['all' => 'All conditions (AND)', 'any' => 'Any condition (OR)'], 'default' => 'all'],
                ['key' => 'conditions', 'label' => 'Conditions', 'type' => 'json', 'help' => 'JSON list of {"metric":"roi","op":"lt","value":-20}. Metrics: clicks, uniques, bots, blocked, leads, purchases, conversions, revenue, cost, profit, roi, cr, epc, cpc. Empty = unconditional.'],
                ['key' => 'actions', 'label' => 'Actions', 'type' => 'json', 'help' => 'JSON list, e.g. [{"type":"pause_campaign"},{"type":"set_flow_weight","flow":"Flow 1","weight":0},{"type":"export_report","range":"today","output":"exports/{date}.csv"},{"type":"update_blacklists"},{"type":"notify","event":"rule","message":"ROI {roi}% on campaign {campaign}"},{"type":"log","message":"hi"}]'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
        ],
        'channels' => [
            'title' => 'Notification Channels',
            'singular' => 'Channel',
            'icon' => 'bi-bell',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Group', 'type' => 'text'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['telegram' => 'Telegram', 'webhook' => 'Webhook', 'email' => 'Email'], 'default' => 'telegram'],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'checkbox', 'default' => true],
                ['key' => 'events', 'label' => 'Events', 'type' => 'csv', 'help' => 'Comma-separated event names this channel listens to (e.g. rule). Empty = all events.'],
                ['key' => 'message', 'label' => 'Message template', 'type' => 'textarea', 'help' => 'Body/text with {token} placeholders, e.g. "Rule {rule} fired: ROI {roi}%".', 'tokens' => ['{event}', '{rule}', '{campaign}', '{roi}', '{revenue}', '{profit}', '{clicks}', '{time}']],
                ['key' => 'bot_token', 'label' => 'Telegram bot token', 'type' => 'text', 'section' => 'Telegram', 'showIf' => ['field' => 'type', 'in' => ['telegram']], 'help' => 'Secret; never logged.'],
                ['key' => 'chat_id', 'label' => 'Telegram chat id', 'type' => 'text', 'section' => 'Telegram', 'showIf' => ['field' => 'type', 'in' => ['telegram']]],
                ['key' => 'parse_mode', 'label' => 'Telegram parse mode', 'type' => 'select', 'options' => ['HTML' => 'HTML', 'Markdown' => 'Markdown', '' => 'None'], 'default' => 'HTML', 'section' => 'Telegram', 'showIf' => ['field' => 'type', 'in' => ['telegram']]],
                ['key' => 'url', 'label' => 'Webhook URL', 'type' => 'text', 'section' => 'Webhook', 'showIf' => ['field' => 'type', 'in' => ['webhook']], 'help' => 'Tokens allowed.', 'tokens' => ['{event}', '{rule}', '{campaign}', '{roi}']],
                ['key' => 'method', 'label' => 'Webhook method', 'type' => 'select', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'default' => 'POST', 'section' => 'Webhook', 'showIf' => ['field' => 'type', 'in' => ['webhook']]],
                ['key' => 'headers', 'label' => 'Webhook headers', 'type' => 'kvlines', 'section' => 'Webhook', 'showIf' => ['field' => 'type', 'in' => ['webhook']], 'help' => 'One per line: Header-Name=value (tokens allowed).'],
                ['key' => 'body', 'label' => 'Webhook body', 'type' => 'textarea', 'section' => 'Webhook', 'showIf' => ['field' => 'type', 'in' => ['webhook']], 'help' => 'Raw body template. Empty = use message template.'],
                ['key' => 'content_type', 'label' => 'Webhook Content-Type', 'type' => 'text', 'default' => 'application/json', 'section' => 'Webhook', 'showIf' => ['field' => 'type', 'in' => ['webhook']]],
                ['key' => 'to', 'label' => 'Email to', 'type' => 'text', 'section' => 'Email', 'showIf' => ['field' => 'type', 'in' => ['email']], 'help' => 'Comma-separated recipients.'],
                ['key' => 'from', 'label' => 'Email from', 'type' => 'text', 'section' => 'Email', 'showIf' => ['field' => 'type', 'in' => ['email']]],
                ['key' => 'subject', 'label' => 'Email subject', 'type' => 'text', 'default' => 'YaAff notification', 'section' => 'Email', 'showIf' => ['field' => 'type', 'in' => ['email']], 'help' => 'Tokens allowed.'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'section' => 'Advanced'],
            ],
        ],
        'roles' => [
            'title' => 'Roles',
            'singular' => 'Role',
            'icon' => 'bi-person-badge',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                ['key' => 'permissions', 'label' => 'Permissions', 'type' => 'permissions', 'help' => 'Toggle View / Manage per resource. Wildcards (*, offers.*, *.view) are honoured and shown as chips.'],
            ],
        ],
        'users' => [
            'title' => 'Users',
            'singular' => 'User',
            'icon' => 'bi-people',
            'fields' => [
                ['key' => 'name', 'label' => 'Username', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'help' => 'Stored only as a hash. Leave blank when editing to keep the current password.'],
                ['key' => 'role', 'label' => 'Role', 'type' => 'entityref', 'entity' => 'roles', 'help' => 'Role granting permissions. The built-in "Admin" role grants everything.'],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'checkbox', 'default' => true],
                ['key' => 'permissions', 'label' => 'Extra permissions', 'type' => 'permissions', 'help' => 'Optional permissions merged on top of the role.'],
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'text', 'help' => 'Bearer token for the REST API (Phase 11). Keep secret.'],
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

/**
 * Catalog of common ISO-4217 currencies for payout/cost selects.
 *
 * @return array<string,string> code => "CODE — Name"
 */
function currency_options(): array
{
    $codes = [
        'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'RUB' => 'Russian Ruble',
        'UAH' => 'Ukrainian Hryvnia', 'KZT' => 'Kazakhstani Tenge', 'TRY' => 'Turkish Lira',
        'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso', 'ARS' => 'Argentine Peso',
        'COP' => 'Colombian Peso', 'CLP' => 'Chilean Peso', 'PEN' => 'Peruvian Sol',
        'INR' => 'Indian Rupee', 'IDR' => 'Indonesian Rupiah', 'PHP' => 'Philippine Peso',
        'VND' => 'Vietnamese Dong', 'THB' => 'Thai Baht', 'MYR' => 'Malaysian Ringgit',
        'SGD' => 'Singapore Dollar', 'HKD' => 'Hong Kong Dollar', 'JPY' => 'Japanese Yen',
        'CNY' => 'Chinese Yuan', 'KRW' => 'South Korean Won', 'AUD' => 'Australian Dollar',
        'NZD' => 'New Zealand Dollar', 'CAD' => 'Canadian Dollar', 'CHF' => 'Swiss Franc',
        'PLN' => 'Polish Zloty', 'CZK' => 'Czech Koruna', 'SEK' => 'Swedish Krona',
        'NOK' => 'Norwegian Krone', 'DKK' => 'Danish Krone', 'RON' => 'Romanian Leu',
        'HUF' => 'Hungarian Forint', 'BGN' => 'Bulgarian Lev', 'ZAR' => 'South African Rand',
        'NGN' => 'Nigerian Naira', 'EGP' => 'Egyptian Pound', 'SAR' => 'Saudi Riyal',
        'AED' => 'UAE Dirham', 'ILS' => 'Israeli Shekel', 'PKR' => 'Pakistani Rupee',
        'BDT' => 'Bangladeshi Taka',
    ];
    $out = [];
    foreach ($codes as $code => $name) {
        $out[$code] = "$code — $name";
    }
    return $out;
}
