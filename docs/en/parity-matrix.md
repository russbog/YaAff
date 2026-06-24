# Keitaro Parity Matrix

Mapping of Keitaro tracker capabilities to this fork (YaAff). Status legend:

- **✓** implemented
- **~** partial / different approach
- **✗** intentionally out of scope

Each row points at the file(s)/phase where the capability lives. Phases refer to
the architecture docs in this folder (`phase-0` … `phase-12`).

## 1. Campaigns & flows

| Capability | Status | Where |
|---|---|---|
| Campaigns as first-class objects | ✓ | `campaigns` table, `admin/index.php`, `admin/campsettings.php` |
| Multiple flows (streams) per campaign | ✓ | flow JSON in campaign settings, `main/` flow engine |
| Flow types: regular / forced / default (fallback) | ✓ | `FlowSelector` (Phase 2) |
| Flow weights / shares | ✓ | `FlowSelector` weighted split (Phase 2) |
| A/B split & weighted distribution | ✓ | `abtest/`, equal / weighted / Thompson sampling |
| Enable/disable campaign & flow | ✓ | `enabled` flag (Phase 8 made `pause_campaign` effective) |
| White/black page logic | ✓ | `white`/`black` settings, `tds/`, `htmlprocessing/` |

## 2. First-class entities

| Capability | Status | Where |
|---|---|---|
| Traffic Sources | ✓ | `entities/Source.php`, `admin/sources.php` (Phase 1) |
| Offers | ✓ | `entities/Offer.php`, `admin/offers.php` (Phase 1) |
| Landing pages | ✓ | `entities/Landing.php`, `admin/landings.php` (Phase 1) |
| Affiliate Networks | ✓ | `entities/Network.php`, `admin/networks.php` (Phase 1) |
| Groups / folders | ✓ | `entities/Group.php`, `group_id` on every entity (Phase 1) |
| Source/network templates | ✓ | `templates/sources/*`, `templates/networks/*` (Phase 1) |
| Use offers/landings inside flow steps | ✓ | `FlowEntityResolver` (Phase 1) |

## 3. Routing / redirect modes

| Capability | Status | Where |
|---|---|---|
| 301/302 redirect | ✓ | `RedirectStrategy` (Phase 2) |
| Meta-refresh / JS redirect | ✓ | `RedirectStrategy` (Phase 2) |
| Curl / reverse-proxy (direct load) | ✓ | `directload/`, `RedirectStrategy` |
| iframe | ✓ | `RedirectStrategy` (Phase 2) |
| Form / double-meta / remote fetch | ✓ | `RedirectStrategy` 12 modes (Phase 2) |
| No-redirect (show content) | ✓ | `RedirectStrategy` (Phase 2) |
| Action / HTML show | ✓ | `actions.php`, `htmlprocessing/` |

## 4. Filters / triggers

| Capability | Status | Where |
|---|---|---|
| GEO country | ✓ | MaxMind, `bases/`, `FilterFunctions` |
| Region / city | ✓ | GeoLite2 City (Phase 2) |
| Device / OS / browser | ✓ | DeviceDetector, filters |
| ISP / connection type | ✓ | filters (Phase 2) |
| Language | ✓ | filters |
| Search engine / keyword | ✓ | `bases/searchengines.json`, filters (Phase 2) |
| Referrer / site | ✓ | filters (Phase 2) |
| Timetable (schedule) | ✓ | filters (Phase 2) |
| Date range | ✓ | `date_between` filter (Phase 2) |
| Click limit / cap | ✓ | `click_limit` filter (Phase 2) |
| Uniqueness | ✓ | `uniqueness` filter (Phase 2) |
| Bots | ✓ | bot filter + Phase 6 blacklists |
| IP / UA / param masks & regex | ✓ | filters with mask/regex (Phase 2) |
| AND/OR condition groups | ✓ | filter query builder (`admin/js/filters.js`) |

## 5. Conversions & Conversion API (CAPI)

| Capability | Status | Where |
|---|---|---|
| Conversions as entity | ✓ | `conversions` table, `admin/conversions.php` (Phase 3) |
| S2S postbacks (in) | ✓ | `api/postback.php`, status mapping |
| Postback audit log | ✓ | `postback_log` (Phase 3) |
| Conversion pixel | ✓ | `api/pixel.php` (Phase 3) |
| Conversion API senders (FB CAPI / Google / TikTok) | ✓ | `Integration` + `ConversionApiSender`, `templates/integrations/*` (Phase 3) |
| Status mapping (lead/sale/rejected…) | ✓ | postback + conversion handling |
| Dedup by configurable key | ✓ | clickid / tid / clickid_tid (Phase 3) |
| CSV conversion import | ✓ | `admin/conversions.php` (Phase 3) |

## 6. Tokens / macros

| Capability | Status | Where |
|---|---|---|
| Unified token system across layers | ✓ | `TokenRegistry` (Phase 4) |
| Sub-IDs (subN), custom params (c.*) | ✓ | `TokenRegistry` |
| Tokens in landings, URLs, postbacks, CAPI | ✓ | `MacrosProcessor`, `ConversionApiSender` delegate to registry (Phase 4) |
| Dynamic tokens (date/random/etc.) | ✓ | `TokenRegistry` dynamic resolvers (Phase 4) |

## 7. Domains

| Capability | Status | Where |
|---|---|---|
| Domains as entity | ✓ | `entities/Domain.php`, `admin/domains.php` (Phase 5) |
| Multiple domains per campaign | ✓ | domain pool (Phase 5) |
| Wildcard / alias domains | ✓ | `DomainMatcher` (Phase 5) |
| Auto-SSL | ✓ | existing acme + install |
| Cloudflare API (DNS, token verify) | ✓ | `domains/`-CF client, `admin/cloudflare.php` (Phase 5) |

## 8. Bot protection

| Capability | Status | Where |
|---|---|---|
| JS bot detection | ✓ | existing JS challenge |
| IP/UA blacklists | ✓ | `bots/` (Phase 6) |
| Auto-updated feeds | ✓ | `bases/blacklists/feeds.json`, `bases/update_blacklists.php` cron (Phase 6) |
| Datacenter / proxy / VPN detection (offline) | ✓ | `bots/` offline checks before slow services (Phase 6) |

## 9. Reporting / statistics

| Capability | Status | Where |
|---|---|---|
| Real-time dashboard | ~ | polling dashboard + KPI cards (`admin/dashboard.php`, Phase 7); not websocket |
| Charts | ✓ | canvas charts, no external libs (Phase 7) |
| Custom report grouping/columns | ✓ | `reports/ReportAggregator`, custom stat tables |
| Click / conversion log | ✓ | `clicks`, `click_event_log`, `admin/conversions.php` |
| Scheduled report export (CSV/JSON) | ✓ | `bin/export_report.php`, `ReportExporter` (Phase 7) |
| Top countries / flows | ✓ | `DashboardQuery` (Phase 7) |

## 10. Automation rules + scheduler

| Capability | Status | Where |
|---|---|---|
| Rules as entity (schedule/conditions/actions) | ✓ | `entities/Rule.php`, `admin/rules.php` (Phase 8) |
| Schedule (interval/cron/macro) | ✓ | `rules/Schedule` (Phase 8) |
| Metric conditions (AND/OR, operators) | ✓ | `RuleEvaluator` (Phase 8) |
| Actions (pause/resume, set weight, export, refresh, notify, log) | ✓ | `RuleActionExecutor` (Phase 8/9) |
| Cron runner + audit log | ✓ | `bin/run_rules.php`, `rule_log` (Phase 8) |

## 11. Notifications

| Capability | Status | Where |
|---|---|---|
| Telegram / webhook / email channels | ✓ | `entities/Channel.php`, `ChannelSender` (Phase 9) |
| Templated messages with tokens | ✓ | `MessageRenderer` (Phase 9) |
| Event dispatch + audit (no secrets) | ✓ | `Notifier`, `notification_log` (Phase 9) |
| Triggered from rules (`notify` action) | ✓ | `bin/run_rules.php` wiring (Phase 9) |

## 12. Multi-user & access control

| Capability | Status | Where |
|---|---|---|
| Multiple users | ✓ | `entities/User.php`, `admin/users.php` (Phase 10) |
| Roles | ✓ | `entities/Role.php`, `admin/roles.php` (Phase 10) |
| Granular permissions (`type.view`/`type.manage`, wildcards) | ✓ | `auth/AccessControl` (Phase 10) |
| bcrypt password hashing | ✓ | `Authenticator` (Phase 10) |
| Backward-compatible single-password mode | ✓ | `auth/Auth` (Phase 10) |

## 13. REST API

| Capability | Status | Where |
|---|---|---|
| CRUD over all entities | ✓ | `api/RestApi.php`, `EntityService` (Phase 11) |
| Bearer-token auth (master + per-user) | ✓ | `api/ApiAuth.php` (Phase 11) |
| Permission enforcement | ✓ | `AccessControl` in `RestApi` (Phase 11) |
| OpenAPI 3.0 spec + Swagger UI | ✓ | `api/OpenApiBuilder`, `admin/api.php` (Phase 11) |

## 14. Database / infrastructure / data

| Capability | Status | Where |
|---|---|---|
| Driver abstraction | ✓ | `db/drivers/DbDriver` (Phase 0) |
| SQLite backend | ✓ | `SqliteDriver` (Phase 0) |
| MySQL / MariaDB backend | ✓ | `MysqlDriver` + `SqlDialect` (Phase 12) |
| Migrations | ✓ | `db/Migrator`, `db/migrations/*` (Phase 0) |
| Backup / restore (portable) | ✓ | `data/BackupManager`, `admin/data.php` (Phase 12) |
| Data retention / pruning | ✓ | `data/RetentionManager`, `bin/run_retention.php` (Phase 12) |
| Replication / clustering | ✗ | out of scope (handled at DB/infra layer) |
| MySQL-specific query tuning | ✗ | out of scope (correctness over backend-specific optimization) |

## 15. Out of scope / notes

- **Built-in WYSIWYG landing editor** — ✗ landings are managed as entities/files, not edited in-app.
- **Real-time websocket stats** — ~ the dashboard polls; functionally equivalent for the supported volumes.
- **Distributed/clustered deployments** — ✗ single-node focus; the MySQL backend is the scaling path.
