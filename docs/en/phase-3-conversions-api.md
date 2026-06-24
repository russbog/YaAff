# Phase 3: Conversions + Conversion API

## Overview

Phase 3 adds a dedicated **conversions** subsystem that sits alongside (not replaces) the existing click-status mechanism. One click can now generate multiple conversions, each keyed by an external transaction ID (`tid`). Deduplication is enforced per campaign on a configurable key. All incoming and outgoing postback activity is recorded in a structured audit log.

A generic **Conversion API** layer sends conversion data to external platforms (Facebook CAPI, Google, TikTok, or any custom HTTP endpoint) via data-driven templates—no hardcoded integrations.

## Architecture

```
postback.php / pixel.php
        │
        ▼
conversion_handler.php
  ├─ dedup check (conversions table)
  ├─ add_conversion()
  ├─ update_status() ← backward compat
  ├─ log_postback("in", ...)
  ├─ process_s2s_postbacks() → log_postback("out", ...)
  └─ fire_conversion_integrations()
         └─ ConversionApiSender::send()
              └─ log_postback("out", ...)
```

## 3.1 Conversions Table + Dedup + Postback Log

### Tables

- **conversions** — `id, campaign_id, clickid, tid, status, payout, revenue, currency, time, source, dedup_key, raw`
- **postback_log** — `id, time, direction(in|out), clickid, status, payout, currency, target, http_code, message`

### Dedup Strategy

Configured per campaign in postback settings (`dedup_key`):
- `clickid_tid` (default) — dedup on `clickid|tid`
- `clickid` — one conversion per click
- `tid` — one conversion per external transaction

### DB Methods

- `Db::add_conversion()` — insert with dedup_key
- `Db::conversion_exists()` — fast lookup by campaign + dedup_key
- `Db::log_postback()` — structured audit entry
- `Db::get_conversions()` — list for admin UI
- `Db::get_postback_log()` — list with optional direction filter

## 3.2 JS Pixel Endpoint

**`api/pixel.php`** — tracks conversions from a landing/thank-you page.

- Always returns 1x1 transparent GIF (or JSON when `format=json`)
- Params: `clickid` (required), `status` (default: Lead), `payout`, `currency`, `tid`
- Uses same `register_conversion()` pipeline as postback.php
- Pixel URL shown in campaign settings

Example:
```html
<img src="/api/pixel.php?clickid=CLK123&status=lead&payout=5" width="1" height="1" />
```

## 3.3 Conversion API (Generic HTTP Templates)

### Integration Entity

First-class entity (`entities/Integration.php`) following the Phase 1 pattern. Settings keys:

| Key | Type | Description |
|-----|------|-------------|
| type | string | Preset ID (generic/fb_capi/google/tiktok) |
| method | string | HTTP method (GET/POST) |
| url | string | Endpoint URL with {token} placeholders |
| headers | object | Header name → value template |
| body | string | Request body template |
| content_type | string | Content-Type header (default: application/json) |
| statuses | array | Statuses that trigger this integration (empty = all) |
| enabled | bool | Master on/off switch |

### ConversionApiSender

Pure template rendering + curl sending:

- `buildTokens($click, $status, $payout, $revenue, $currency, $request)` — flat token map from click data
- `renderTemplate($template, $tokens)` — replace `{token}` placeholders
- `buildRequest($integration, $tokens)` — construct method/url/headers/body
- `send($integration, $tokens)` — execute with CONNECT_TIMEOUT=2s, TOTAL_TIMEOUT=3s

### Available Tokens

| Token | Source |
|-------|--------|
| {clickid}, {ip}, {country}, {ua} | Click columns |
| {c.NAME} | Click params (custom) |
| {status}, {payout}, {revenue}, {currency}, {time} | Conversion fields |
| {tid}, {sub1}...{subN} | Request params |

### Preset Templates

JSON files in `templates/integrations/`:
- **generic.json** — starting point for any platform
- **fb_capi.json** — Facebook Conversions API
- **google.json** — Google Measurement Protocol (GA4)
- **tiktok.json** — TikTok Events API

## 3.4 Bulk CSV Import

Admin UI (`admin/conversions.php` → "Bulk Import" tab) accepts CSV files:

**Required columns:** `clickid`, `status`
**Optional columns:** `payout`, `currency`, `revenue`, `tid`/`transaction_id`

Each row is validated (clickid exists, status maps to internal status), deduplicated, and processed through the standard conversion pipeline.

## Admin UI

- **Conversions page** (`admin/conversions.php`) — three tabs: Conversions list, Postback Log, Bulk Import
- **Integrations page** (`admin/integrations.php`) — CRUD for Conversion API integrations with preset templates
- **Campaign settings** — new "Conversion Settings" section: dedup key selector, integration checkboxes, pixel URL display

## Backward Compatibility

- `api/postback.php` still accepts the same parameters (clickid, status, payout, currency)
- Click status is still updated via `update_status()` for existing statistics
- S2S outgoing postbacks still fire on conversion
- No changes to the statistics/reporting layer
