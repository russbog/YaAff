# Phase 5: Domains Management + Cloudflare + Domain Pool

## Overview

Phase 5 makes domains a first-class, reusable entity (like Sources, Offers and
Networks) and adds optional Cloudflare DNS automation. Campaigns still keep
their own `domains` list, so nothing changes for existing campaigns — the new
domain pool is **additive**:

- A **domain pool** managed in the admin (`Domains` page), with grouping.
- **Wildcard** support (`*.example.com`) shared by the whole tracker through one
  pure matcher.
- **Aliases**: a pool domain can point at a canonical host; incoming requests on
  an alias resolve to the canonical campaign domain automatically.
- **Cloudflare API** integration to create DNS records straight from the admin,
  with short timeouts and a deterministic fallback (never blocks the response).

Everything is data-driven: all DNS/Cloudflare/alias attributes live in the
entity's `settings` JSON bag, so there is no hardcoding for a particular domain
or account.

## Entity

`Domain` (`entities/Domain.php`) follows the standard entity convention — the
hostname is stored in the core `name` column; the rest lives in `settings`:

| Key            | Meaning                                                        |
|----------------|---------------------------------------------------------------|
| `type`         | `regular` \| `wildcard` \| `alias`                            |
| `alias_of`     | canonical host an `alias` resolves to                         |
| `campaign_id`  | optional campaign this domain is reserved for                 |
| `dns_type`     | `A` \| `CNAME` \| `AAAA` \| `TXT` (record to create)         |
| `dns_content`  | record value (IP for A/AAAA, target host for CNAME)          |
| `dns_proxied`  | whether the Cloudflare record is proxied (orange cloud)      |
| `cf_zone_id`   | Cloudflare zone id                                            |
| `cf_api_token` | scoped Cloudflare API token (never logged or echoed back)    |
| `note`         | free-form note                                               |

The migration `db/migrations/009_create_domains.php` creates the `domains` table
using the same shape as every other entity.

## Domain matching & aliases

`DomainMatcher` (`domains/DomainMatcher.php`) is a pure, unit-tested helper that
centralizes hostname matching:

```php
DomainMatcher::matches(['*.example.com'], 'go.example.com'); // true
DomainMatcher::resolveAlias(['a.com' => 'b.com'], 'a.com');  // 'b.com'
```

`Db::get_campaign_by_domain()` now:

1. Builds an alias map from the domain pool (empty when the table is absent, so
   older installs keep working).
2. Resolves the incoming host through the alias map.
3. Matches campaigns against **both** the original and the resolved host.

The previous inline wildcard logic in `Db::match_domain()` was replaced by a
thin delegation to `DomainMatcher::matches()` — behaviour is unchanged for
existing campaigns.

## Cloudflare automation

`CloudflareClient` (`domains/CloudflareClient.php`) is a generic client for the
Cloudflare API. The request builders are pure; `send()` performs the HTTP call
with `CONNECT_TIMEOUT = 2s` / `TOTAL_TIMEOUT = 3s` and **never throws** — on any
failure it returns a deterministic result envelope:

```php
[ 'ok' => bool, 'http_code' => int, 'error' => string, 'result' => array, 'raw' => string ]
```

The admin endpoint `admin/cloudflare.php` exposes two actions for a given
domain id:

- `?action=verify_token&id=N` — verify the domain's stored API token.
- `?action=create_record&id=N` — create the configured DNS record in the zone.

Credentials are always read from the domain's own settings and are never
returned in responses.

## Backward compatibility

- Campaign `settings['domains']` continue to drive matching exactly as before.
- The domain pool and alias resolution are additive; with an empty pool the
  behaviour is identical to Phase 4.
- All Cloudflare calls degrade gracefully — a Cloudflare outage cannot affect
  click handling.

## Tests

- `tests/DomainMatcherTest.php` — exact/wildcard matching, alias map building,
  alias chain resolution (including cycle guard).
- `tests/CloudflareClientTest.php` — request building, input validation,
  response parsing (success / API errors / curl error / invalid JSON).
- `tests/DomainEntityTest.php` — entity accessors and normalization.
