# Phase 4: Unified Token System (TokenRegistry)

## Overview

Phase 4 introduces a single source of truth for resolving `{token}`
placeholders across the whole tracker. Before this phase token resolution was
duplicated: `MacrosProcessor` resolved tokens for landing HTML and outgoing
URLs, while the Conversion API (`ConversionApiSender::buildTokens`) built its
own flat token map. The two understood slightly different token sets and had
divergent rules.

`TokenRegistry` unifies all of this: every layer — landing pages, URL macros,
S2S postbacks, and the Conversion API — now resolves the same token names the
same way, from one click context.

## Architecture

```
              ┌──────────────────────────────┐
              │        TokenRegistry          │
              │  resolve() / render() /        │
              │  toArray()                     │
              └──────────────────────────────┘
                 ▲            ▲            ▲
                 │            │            │
        MacrosProcessor  ConversionApiSender  (future layers)
         (landings,       (buildTokens →
          URLs, S2S)       toArray)
```

- `MacrosProcessor` delegates value resolution to a `TokenRegistry` built with
  a lazy DB loader (loads the click row on first miss).
- `ConversionApiSender::buildTokens()` builds a registry from the click row plus
  conversion overrides (status/payout/revenue/currency/time + request params)
  and returns `toArray()`.

## Token Context

A registry is constructed around one click:

- **clickid / userid** — identity tokens.
- **click row** — columns (`ip`, `country`, `os`, `ua`, …) and custom `params`.
- **overrides** — explicit tokens that always win (conversion `status`,
  `payout`, `revenue`, `currency`, `time`, request params such as `tid`).
- **loader** (optional) — a callback that lazily loads the click row from the DB
  when a column/param is requested but not already present.

## Token Reference

| Token | Source |
|-------|--------|
| `{clickid}`, `{userid}` | click identity |
| `{domain}` | `HTTP_HOST` |
| `{time}` | current unix time |
| `{px}` | `px` cookie |
| `{ip}` `{country}` `{region}` `{city}` `{lang}` `{os}` `{osver}` `{client}` `{clientver}` `{device}` `{brand}` `{model}` `{isp}` `{connection_type}` `{ua}` `{status}` | click columns |
| `{c.NAME}` | custom click param `NAME` |
| `{_NAME}` | forces the incoming query param `NAME`, bypassing built-in tokens (e.g. `{_domain}` reads `?domain=...` instead of the redirect host) |
| `{*NAME}` | in `renderUrl()`, inserts the value literally without URL-encoding (e.g. `{*_domain}` keeps `/` intact); combine with `_`. Bare `{NAME}` is URL-encoded as usual |
| `{sub1}` … `{subN}`, `{sub_id_N}` | click param of the same name (Keitaro-style) |
| `{hash:TOKEN}` | md5 of another token's value |
| `{random:A-B}` | random integer in `[A, B]` |

Conversion overrides also expose `{status}`, `{payout}`, `{revenue}`,
`{currency}`, `{time}` and any request params (e.g. `{tid}`) — these win over
click data of the same name.

## API

```php
$r = new TokenRegistry($clickid, $userid, $clickRow, $overrides, $loader);
$r = TokenRegistry::fromClick($clickRow, $overrides);   // Conversion API path
$r2 = $r->withOverrides(['status' => 'Purchase']);      // copy + merge

$r->resolve('country');          // ?string  — null if unknown/empty
$r->render('id={clickid}&g={country}');  // inline substitution, unknown left intact
$r->toArray();                   // flat {token}=>value map for templates
```

Resolution order: **overrides → forced query params (`_NAME`) → dynamic tokens
(clickid/userid/domain/time/px/hash/random) → custom params (`c.*`, `subN`) →
click columns**. Unknown tokens
resolve to `null` (and are left intact by `render()`).

## Tokens in Landings

`MacrosProcessor::replace_html_macros()` keeps its backward-compatible handling
of `{clickid}`, `{userid}` and `{px}` (replaced with an empty string when
absent), then additionally renders the full token set through the registry.
Landing pages can now use any token (e.g. `{country}`, `{c.fbclid}`). Unknown
placeholders are left untouched, so existing landing markup is unaffected.

## Backward Compatibility

- `MacrosProcessor`'s public methods (`replace_html_macros`, `replace_url_macros`)
  keep their signatures and substitution semantics.
- `ConversionApiSender::buildTokens()` returns the same map shape as before
  (verified by the existing Conversion API tests).
- URL macros still only substitute query values that are exactly `{macro}`;
  unresolved macros are left in place and logged.

## Tests

`tests/TokenRegistryTest.php` covers resolution (identity, columns, custom
params, sub-tokens, overrides, hash, random), `render()` (substitution,
unknown-intact, short-circuits), `toArray()`, `withOverrides()` and lazy
loading.
