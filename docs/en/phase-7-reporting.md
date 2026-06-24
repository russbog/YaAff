# Phase 7: Reporting — Real-time Dashboard, Charts & Scheduled Exports

Phase 7 adds a live operational view on top of the existing saved-table
statistics (`admin/statistics.php`): a **real-time dashboard** with KPI cards and
a time-series chart, plus a **generic, data-driven report exporter** suitable for
cron-scheduled CSV/JSON output. Conversion and postback logs already exist in the
**Conversions** page (Phase 3); the dashboard complements them with aggregate,
auto-refreshing metrics.

## Components

### `reports/ReportAggregator.php` (pure)
Derives KPIs from raw base counters. No I/O — fully unit-tested and reused by the
dashboard, exporter and (later) the REST API.

- `metrics(array $base): array` → merges base counters with derived
  `profit`, `roi`, `cr`, `epc`, `cpc`, `uniques_ratio`, `bot_ratio`.
  All divisions are zero-safe.
- `mergeSeries(array $clickBuckets, array $convBuckets): array` → joins the
  clicks and conversions time-series into one ordered, zero-filled series.

### `reports/DashboardQuery.php`
Live queries decoupled from the large `Db` class so they run against any
`DbDriver` (SQLite today, MySQL later) and are unit-tested with a seeded driver.
`campId = 0` means *all campaigns*.

- `summary($campId, $start, $end)` → clicks/uniques/cost (from `clicks`),
  blocked/bots (from `blocked`, `reason = 'bot'`), conversions/revenue/leads/
  purchases/rejects (from `conversions`), passed through `ReportAggregator`.
- `timeseries($campId, $start, $end, $tzOffset)` → per-day buckets via
  `DbDriver::dateGroup` (timezone-aware), merged with `mergeSeries`.
- `topBy($field, $campId, $start, $end, $limit)` → top breakdown rows for a
  whitelisted click dimension (`country`, `isp`, `os`, `device`, `flow`, …).
  Unknown fields return `[]` (no SQL injection surface).

### `reports/ReportExporter.php` (pure)
Serialization helpers, no I/O.

- `flattenTree($tree, $groupBy)` → flattens the nested tree produced by
  `Db::get_statistics` into flat rows, carrying each grouping level as a column.
- `toCsv($rows, $columns = null)` → RFC-4180-style CSV (quotes/escapes `" , \r \n`).
- `toJson($rows)` → compact JSON.

## Dashboard UI

`admin/dashboard.php` (nav: **Dashboard**) renders KPI cards, a dependency-free
canvas line chart (Clicks vs Conversions) and Top countries / Top flows tables.
It polls `dashboard.php?action=data` with the browser timezone, a campaign filter,
a period selector (1h / 24h / 7d / 30d) and a configurable auto-refresh interval.
The chart is drawn on a plain `<canvas>` — no external charting library or CDN.

JSON endpoint:

```
GET admin/dashboard.php?action=data&campId=<id>&start=<ts>&end=<ts>&tz=<IANA tz>
→ { summary:{...}, series:[...], top_country:[...], top_flow:[...] }
```

## Scheduled / CLI exports

`bin/export_report.php` is a generic, data-driven export entry point for cron.

```bash
php bin/export_report.php path/to/report.json [output/path]
```

Report config (data-driven JSON — adding a report is a config change, not code):

```json
{
  "name": "daily-geo",
  "campaign_id": 12,
  "timezone": "UTC",
  "range": "today",
  "fields": ["clicks", "uniques", "conversion", "revenue", "profit"],
  "group_by": ["date", "country"],
  "format": "csv",
  "output": "exports/{name}-{date}.csv"
}
```

- `range`: `today` | `yesterday` | `7d` | `30d` | `<seconds>`.
- `output` tokens: `{name}`, `{date}`, `{ts}`. Omit `output` to write to STDOUT.
- `group_by` reuses the saved-table engine (`Db::get_statistics`), so any column
  or `param.*` JSON key that statistics support is available here too.

Reports are produced **per campaign** (`campaign_id`), matching the statistics
engine. Delivery (email/webhook) and scheduling are deliberately left to the
Phase 8 scheduler and Phase 9 notifications, which wrap this generic exporter.

Example cron (hourly):

```
0 * * * * php /path/to/YellowTDS/bin/export_report.php /path/to/report.json
```

## Tests

13 new tests:

- `ReportAggregatorTest` — derived metrics, zero-safe denominators, series merge
  with zero-filled gaps and ordering.
- `ReportExporterTest` — nested/flat tree flattening, CSV quoting/escaping,
  explicit column selection, JSON.
- `DashboardQueryTest` — seeded SQLite: per-campaign and all-campaign summaries,
  top-by breakdown, unknown-field rejection, day-bucketed time-series.
```
