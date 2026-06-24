# Phase 6: Bot Protection — Auto-updated IP/UA Blacklists

Phase 6 adds offline, auto-updatable IP/User-Agent blacklists and offline
datacenter/proxy/VPN detection on top of the existing JS bot detection and the
DeviceDetector bot signal. Matching happens entirely from local cache files, so
**no per-click network call** is required.

## Components

### `bots/FeedParser.php` (pure)
Normalizes any line-oriented feed body into a clean list:
- `parseIp(string $raw): array` — extracts IPv4/IPv6 addresses and CIDRs, drops
  `#`/`;` comments, blank lines and junk, de-duplicates.
- `parseUa(string $raw): array` — lower-cased, de-duplicated UA tokens.

### `bots/BlacklistStore.php`
Offline cache + matcher. Each feed is cached as `<feed>.ip` or `<feed>.ua` in
`bases/blacklists/`. Files of each type are lazily aggregated.
- `matchesIp(string $ip): bool` — CIDR/exact match via `IpUtils::checkIp`.
- `matchesUa(string $ua): bool` — case-insensitive substring match.
- `writeFeed(name, type, lines)` — atomic write (temp + rename).

### `bots/BlacklistUpdater.php`
Downloads configured feeds and refreshes the cache. The HTTP fetcher is
injectable (unit-testable). **Failure is non-fatal**: a feed outage leaves the
previous cache untouched, so blacklists are never emptied. Default fetcher uses
curl with `CONNECT_TIMEOUT=5`, `TOTAL_TIMEOUT=60` (cron context, out of the
request path).

## Configuration — `bases/blacklists/feeds.json`

Data-driven. Each feed: `name`, `type` (`ip`|`ua`), `tag` (free label, e.g.
`datacenter`/`abuse`/`bot`), `url`, `enabled`.

```json
{
  "feeds": [
    { "name": "firehol_level1", "type": "ip", "tag": "datacenter",
      "url": "https://.../firehol_level1.netset", "enabled": true }
  ]
}
```

Adding/removing a feed is a configuration change only — no code edits.

A small built-in UA token list ships at `bases/blacklists/builtin.ua` so common
automation user-agents are flagged out of the box.

## Updating

- CLI / cron: `php bases/update_blacklists.php [path/to/feeds.json]`
  ```cron
  0 * * * * php /path/to/bases/update_blacklists.php >> /var/log/blacklists.log 2>&1
  ```
- Admin: **Bot Protection** page (`admin/blacklists.php`) shows feed status and
  has an **Update now** button. JSON actions: `?action=status`, `?action=update`.

## Integration with filtering

- `core.php` `get_click_params()` sets `bot=1` when DeviceDetector reports a bot
  **or** the UA matches a blacklist token — strengthening the existing `bot`
  filter.
- `core.php` `is_proxy_or_vpn()` first consults the offline IP blacklist
  (datacenter/proxy/VPN ranges) before the slower external services, so the
  existing `VPN&Tor` filter now works fast and offline.

Both integrations are additive: with an empty cache, behavior is unchanged
(backward compatible).

## Tests

- `tests/FeedParserTest.php` — IP/UA parsing, comments, dedup, IPv6.
- `tests/BlacklistStoreTest.php` — CIDR/exact/UA matching, multi-feed aggregation,
  cache reload, path sanitization.
- `tests/BlacklistUpdaterTest.php` — write, skip disabled, graceful failure
  fallback, invalid config, empty-parse handling, feed config loading.
