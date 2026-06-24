# YaAff

YaAff is a professional affiliate traffic routing and conversion management system based on a fork of YellowTDS. It keeps compatibility with YellowTDS/YellowCloaker runtime integration patterns while moving the product toward a Keitaro-like TDS: campaigns, flows, postbacks, reports, entities, API, RBAC, integrations, and production tooling in one admin panel.

> YaAff is a fork of YellowTDS. `YellowCloaker` references in the PHP Connect user-agent/client API remain intentionally for backward compatibility with existing integrations.

## Capabilities

### Traffic distribution

- Campaigns with domains, trafficback, and per-campaign statistics settings.
- White/black branches, multi-step funnels, and flows.
- Traffic distribution modes: equal, weighted, and Thompson Sampling.
- Redirect strategies: HTTP 301/302/307, 404, curl/proxy, remote, iframe/meta/script scenarios.
- Rules and filters for IP, geo, ASN, language, user-agent, devices, referrers, and custom parameters.
- JS Connect, PHP Connect, and documented runtime wrappers: `index.php`, `js/index.php`, `phpconnect.php`, `postback.php`, `updateparams.php`, `send.php`, `next.php`.

### Affiliate operations

- Campaign entities: sources, networks, offers, landings, domains, and integrations.
- S2S postbacks and conversion processing.
- Lead-form forwarding through `send.php` with safe upstream error handling that does not expose POST/debug data.
- Conversion API integrations for sending events to external networks.
- Macros and URL parameterization for offers, landings, and postbacks.

### Analytics and control

- Dashboard, campaign statistics, click logs, conversions, and custom table columns.
- Report exports and statistics aggregators.
- Timezone-aware date picker and per-campaign timezone settings.
- REST API and OpenAPI endpoint for automation.
- RBAC: users, roles, and permissions.
- Bot protection through blacklist feeds, offline matching, and scheduled refresh.
- Rules scheduler, notifications, retention, and backup utilities.

### Modern admin UI

- New YaAff branding instead of legacy YellowTDS/YellowCloaker logo assets.
- Modern dark control panel with glassmorphism cards, refreshed navigation, buttons, forms, and tables.
- New login screen and SVG favicon.

## Quick start

### VPS auto-installer

For a clean Debian/Ubuntu VPS, use the installer from this repository:

```bash
curl -fsSL https://raw.githubusercontent.com/russbog/YaAff/multipleconfigs/install.sh | sudo bash
```

The script asks for a domain, verifies DNS points to the VPS, installs nginx/PHP/HTTPS, installs the MaxMind C extension, and offers to download GeoLite2 databases.

To add domains to an existing instance:

```bash
curl -fsSL https://raw.githubusercontent.com/russbog/YaAff/multipleconfigs/install.sh | sudo bash -s -- --add-domain
```

Domains may be entered as a comma-separated list: `tds1.example.com,tds2.example.com`.

### Manual install

1. Deploy the repository contents to hosting with PHP 8.2+.
2. Run `composer install` if you need development/test dependencies.
3. Open `settings.php` and configure at minimum:
   - `adminPassword`
   - `dbConnection`
   - `debug` (`false` for production)
   - `adminDomain` when needed
   - `adminIp` when needed
4. Make sure PHP can write to:
   - `db/`
   - `logs/`
   - `caching/`
5. Open `/admin/` and create a campaign.

## Runtime entrypoints

- `index.php` — main runtime entry point.
- `js/index.php` — JS Connect.
- `phpconnect.php` — PHP Connect API compatibility endpoint.
- `postback.php` — incoming S2S postbacks.
- `updateparams.php` — click parameter updates.
- `send.php` — lead-form forwarding to external affiliate endpoints.
- `next.php` — funnel step transitions.
- `api/rest.php` and `api/openapi.php` — REST API and OpenAPI schema.
- `admin/` — YaAff admin panel.

## Development checks

```bash
composer install
php ./vendor/bin/phpunit --colors=never
find . -name '*.php' -not -path './vendor/*' -not -path './thankyou/vendor/*' -print0 | xargs -0 -n1 php -l
php -S 127.0.0.1:8090 -t "$PWD"
```

To test the real `send.php` failure path, temporarily set `"debug" => false` in `settings.php`, then restore the original value.

## Documentation

Historical YellowTDS/YellowCloaker documentation remains in `docs/` and is being updated for YaAff over time:

- [Russian documentation](docs/ru/index.md)
- [English documentation](docs/en/index.md)
