# Phase 2: Routing Modes, Extended Filters & Flow Types

Phase 2 brings the routing engine, filter set, and flow model up to Keitaro
parity. Everything is data-driven and backward compatible: existing campaigns
keep working unchanged (numeric redirect codes, current filters, unnamed flows).

## 2.1 Unified redirect modes

A single redirect mode enum is now available at step / offer / landing level.
Modes are rendered by `redirects/RedirectStrategy.php` and dispatched from
`redirect.php` / `actions.php`.

| Mode | Kind | Description |
|------|------|-------------|
| `http_301` / `http_302` / `http_307` | server | HTTP `Location` redirect with the given status code |
| `http_404` | server | Returns 404 Not Found |
| `js` | client | `window.location` redirect via inline script |
| `meta` | client | `<meta http-equiv="refresh">` redirect |
| `double_meta` | client | Two-hop meta refresh that drops the referrer |
| `blank_referrer` | client | Meta refresh with `referrer=no-referrer` |
| `formsubmit` | client | Auto-submitted POST form (carries POST data) |
| `iframe` | client | Embeds the target in a full-page iframe |
| `curl` | server | Server-side cURL proxy (no redirect) |
| `remote` | server | Remote reverse-proxy |
| `inline` | content | Inline HTML content |
| `custom_json` | content | Custom body with configurable `Content-Type` |

Backward compatibility: integer codes (`301/302/303/307`) are still accepted
everywhere and normalized through `RedirectStrategy::normalizeMode()`. The legacy
`js` string return value used by `send.php` is preserved.

## 2.2 Extended filters (Keitaro level)

New filter types are available in the AND/OR query builder (admin filters panel),
implemented in `core.php` with pure helpers in `filters/FilterFunctions.php`:

- **region, city** — geo-precise targeting (requires `GeoLite2-City.mmdb`).
- **connection_type** — connection type from the GeoIP ISP/ASN data.
- **search_engine** — detected from the referer using the data table
  `bases/searchengines.json` (host patterns + keyword params), no hardcoding.
- **keyword** — search keyword extracted from the referer query string.
- **site** — referer host.
- **creative_id, x_requested_with** — extra campaign / header parameters.
- **timetable** — weekday + hour ranges with timezone, e.g.
  `[{"days":[1,2,3,4,5],"from":9,"to":18}]` (1=Mon..7=Sun).
- **date_between** — date range `from,to` (`YYYY-MM-DD`).
- **click_limit** — hourly / daily / total click caps per flow, e.g.
  `{"window":"day","limit":1000}` (`window`: `hour|day|total`).
- **uniqueness** — unique vs. repeat click (by userid/clickid in DB).
- **bot** — bot detection promoted to a first-class filter (see 2.3).
- **named campaign params** — `sub_id_1..30`, `keyword`, `creative_id`, `site`
  resolve from the request, falling back to the query string.

### New comparison operators

In addition to the existing operators, comparisons now support:

- **masks** — `*` (any chars) and `?` (single char), e.g. `promo_*`.
- **regex** — `/pattern/` delimited PCRE, e.g. `/^utm_/i`.
- `matches` / `not_matches` operators in the query builder apply either masks or
  regex automatically depending on the value.

## 2.3 Bot / VPN / proxy as query-builder filters

`bot` and `vpntor` (VPN/Tor/proxy) are now ordinary filter conditions and can be
combined with any other condition inside the AND/OR builder, rather than being
separate hardcoded toggles. (Auto-updating local IP-range databases land in
Phase 6.)

## 2.4 Flow types + weight-based rotation

Flows now have a **type** and a **weight** (`campaign.php` `FlowSettings`,
selected by `flow/FlowSelector.php`):

- **forced** — evaluated first, in order; the first matching forced flow wins
  outright.
- **regular** — matching regular flows participate in a weighted random split.
- **default** — fallback; a matching default flow is used only when no regular
  flow matched.

Selection order: forced → regular (weighted) → default. Flows without an explicit
type default to `regular` with weight `100`, so existing campaigns behave exactly
as before.

## Files

- `redirects/RedirectStrategy.php`, `redirect.php`, `actions.php`, `next.php`
- `core.php`, `filters/FilterFunctions.php`, `bases/searchengines.json`
- `campaign.php`, `tds.php`, `flow/FlowSelector.php`
- Admin: `admin/js/filters.js`, `admin/js/flows/collectors.js`,
  `admin/campsettings.php`, `admin/entityschemas.php`

## Verifying

Run the unit tests:

```
php phpunit.phar
```

38 new tests cover filter functions, flow selection, redirect strategies, and
extended filters (79 total passing).
