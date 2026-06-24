# Phase 8: Automation Rules + Scheduler

Phase 8 adds a Keitaro-style **automation engine**: data-driven rules that run on
a schedule, evaluate metric conditions over a reporting window and execute
actions (pause/resume a campaign, correct flow weights, export a report, refresh
blacklists, …). Everything is configuration — a rule is a first-class entity with
its schedule, conditions and actions stored in the schemaless `settings` JSON
bag, so new automations need no code.

## Rule entity

Rules are managed through the generic entity admin (**Rules** in the nav,
`admin/rules.php`) and stored in the `rules` table (migration `010`). The audit
trail lives in `rule_log` (migration `011`).

Settings keys (`entities/Rule.php`):

| Key | Type | Meaning |
|-----|------|---------|
| `enabled` | bool | Master on/off (default true). |
| `schedule` | string | Cadence — interval / cron / macro (see below). |
| `timezone` | string | IANA tz for cron evaluation and the metric window. |
| `campaign_id` | int | Metric scope. `0` = all campaigns. |
| `window` | string | Metric window: `today`, `yesterday`, `1h`, `24h`, `7d`, `30d`, `<seconds>`. |
| `match` | string | `all` (AND) or `any` (OR) over conditions. |
| `conditions` | array | List of `{metric, op, value}`. Empty = unconditional. |
| `actions` | array | List of `{type, ...params}`. |
| `last_run` | int | Bookkeeping: unix epoch of last run (managed automatically). |

## Schedule (`rules/Schedule.php`, pure)

A single string drives the cadence:

- **Interval**: `300` (seconds), `every:5m`, `every:2h`, `every:1d`.
- **Cron** (5 fields `m h dom mon dow`): supports `*`, lists `a,b`, ranges
  `a-b`, steps `*/n`. `7` is accepted as Sunday.
- **Macros**: `@hourly`, `@daily`/`@midnight`, `@weekly`, `@monthly`, `@yearly`.
- **Empty**: runs on every scheduler invocation.

`isDue($now, $lastRun)` is pure and fully unit-tested. Interval schedules compare
elapsed time; cron schedules match calendar fields in the rule timezone and never
fire twice in the same minute.

## Conditions (`rules/RuleEvaluator.php`, pure)

Each condition is `{metric, op, value}`. Metrics come from the Phase 7
`ReportAggregator`: `clicks`, `uniques`, `bots`, `blocked`, `leads`,
`purchases`, `conversions`, `revenue`, `cost`, `profit`, `roi`, `cr`, `epc`,
`cpc`, … Operators: `gt`/`>`, `gte`/`>=`, `lt`/`<`, `lte`/`<=`, `eq`/`=`,
`neq`/`!=`. A missing metric is treated as `0`. `match` combines them with AND
(`all`) or OR (`any`).

## Actions (`rules/RuleActionExecutor.php`)

Actions are dispatched by `type` to pluggable handlers, so later phases add new
behaviours by registering a handler — no changes here (Phase 9 notifications plug
in this way). A failing action never aborts the rest (each is wrapped, mirroring
the tracker's graceful-degradation policy).

Built-in handlers:

| Type | Params | Effect |
|------|--------|--------|
| `pause_campaign` | `campaign_id?` | Sets the campaign `enabled = false`. |
| `resume_campaign` | `campaign_id?` | Sets the campaign `enabled = true`. |
| `set_flow_weight` | `flow`, `weight`, `campaign_id?` | Sets a flow's weight by name. |
| `export_report` | `range`, `fields`, `group_by`, `format`, `output`, … | Writes a report via the Phase 7 exporter. `output` tokens: `{date}`, `{ts}`, `{ext}`. |
| `update_blacklists` | `feeds?` | Refreshes the Phase 6 IP/UA blacklists. |
| `log` | `message` | No-op audit entry. |

`campaign_id` defaults to the rule's `campaign_id` when omitted.

## Scheduler (`rules/RuleScheduler.php`)

`RuleScheduler::run($now)` loads enabled rules, fires those whose schedule is
due, evaluates conditions against a `DashboardQuery` window and runs matching
actions. Every run advances `last_run` and writes a `rule_log` row (matched flag,
metrics snapshot, per-action results). It is decoupled from the request path and
unit-tested with a seeded in-memory driver and fake handlers.

## Cron entry point

```
* * * * * php /path/to/YellowTDS/bin/run_rules.php >> /var/log/yatds-rules.log 2>&1
```

Run once a minute. Idempotency is guaranteed by interval/cron logic plus the
per-rule `last_run`, so a rule never fires more often than configured.

## Example rules

Pause a campaign whose ROI collapses on meaningful volume:

```json
{
  "enabled": true,
  "schedule": "@hourly",
  "timezone": "UTC",
  "campaign_id": 12,
  "window": "today",
  "match": "all",
  "conditions": [
    {"metric": "clicks", "op": "gte", "value": 200},
    {"metric": "roi", "op": "lt", "value": -20}
  ],
  "actions": [
    {"type": "pause_campaign"},
    {"type": "log", "message": "paused on low ROI"}
  ]
}
```

Unconditional scheduled maintenance (no conditions = always runs when due):

```json
{
  "enabled": true,
  "schedule": "*/30 * * * *",
  "conditions": [],
  "actions": [
    {"type": "update_blacklists"},
    {"type": "export_report", "range": "today", "group_by": ["date","country"], "output": "exports/daily-{date}.csv"}
  ]
}
```

## Tests

21 new tests:

- `ScheduleTest` — interval units, cron fields (steps/lists/ranges), macros,
  timezone handling, no double-fire within a minute.
- `RuleEvaluatorTest` — AND/OR matching, missing-metric-as-zero, operator
  aliases.
- `RuleSchedulerTest` — seeded SQLite: due/disabled/not-yet-due rules, matched
  vs unmatched runs, `last_run` advancement, `rule_log` writes, unknown-action
  and exception handling, window resolution.
