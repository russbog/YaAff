# Phase 1: First-class Entities (Networks, Sources, Offers, Landings)

Phase 1 introduces the first reusable business entities on top of the Phase 0
foundation (driver abstraction + `EntityRepository` + migrations). Each entity
is **generic and data-driven**: all its configuration lives in the schemaless
`settings` JSON bag, so there is no hardcoding to a specific network, source,
offer or landing.

Five tables are added, all following the standard entity column convention
(`id, name, group_id, settings, created_at, updated_at`):

| Table | Purpose |
| --- | --- |
| `groups` | Folders for organizing any entity type. |
| `networks` | Affiliate networks (postback template, status mapping, currency). |
| `sources` | Traffic sources (param mapping, S2S postback, cost). |
| `offers` | Offers (payout, caps, network link, redirect type). |
| `landings` | Landings (local folder / remote URL, protection). |

Existing campaigns, clicks, filters, statistics and postbacks are untouched —
the new tables are additive and applied via versioned migrations
(`db/migrations/001`–`005`), so upgrading an existing install never wipes data.

## Admin UI

Each entity gets its own admin page, all generated from one declarative schema:

- `admin/networks.php`, `admin/sources.php`, `admin/offers.php`,
  `admin/landings.php` — thin wrappers that set `$entityType` and include the
  shared template `admin/entitypage.php`.
- `admin/entityschemas.php` — the **single source of truth** for the form
  fields of every entity. Adding a field is a one-line data change here; no new
  code is required.
- `admin/entityapi.php` — one generic JSON endpoint handling
  `list / get / save / delete / templates` for every entity type.
- `admin/js/entityadmin.js` — builds the form from the schema, lists records,
  and talks to the API.

### Field types

The schema (`admin/entityschemas.php`) supports these field types, rendered
automatically by the JS form builder:

| Type | Stored as | UI |
| --- | --- | --- |
| `text`, `number`, `textarea` | string / number | plain inputs |
| `select` | string | dropdown from `options` |
| `checkbox` | bool | toggle |
| `csv` | array | comma-separated input |
| `kvlines` | object | one `key=value` per line |
| `json` | array/object | raw JSON textarea |
| `entityref` | int id | dropdown of another entity type |

## Templates (prefills)

Popular networks and sources ship as **data**, not code, under `templates/`:

- `templates/networks/` — `generic`, `everad`, `terraleads`
- `templates/sources/` — `facebook`, `google`, `tiktok`, `propellerads`,
  `exoclick`

When creating a new entity, a "Start from template" picker offers these as
prefills. Each template is a JSON file of the form:

```json
{
  "name": "Facebook",
  "settings": {
    "param_map": [
      {"alias": "sub1", "token": "sub_id_1", "macro": "{{campaign.id}}"}
    ],
    "cost_param": "cost",
    "note": "..."
  }
}
```

Add a new template by dropping a JSON file into the relevant folder — no code
change needed.

## Flow integration (offers & landings in steps)

Flow steps can now reference first-class offers and landings instead of
hand-typed URLs/folders. `StepSettings` (`campaign.php`) gains two optional,
backward-compatible fields:

```json
{
  "action": "redirect",
  "offers": [12, 15],
  "landings": [3]
}
```

At routing time `FlowEntityResolver::expandStep()`
(`entities/FlowEntityResolver.php`) resolves these references into the step's
existing `redirectUrls` / `folderNames` structures, so the rest of the routing
engine is unchanged:

- **Offers** → redirect URL entries (label = offer name).
- **Remote landings** → redirect URL entries.
- **Local landings** → folder names.

Resolution is **additive** (explicit URLs/folders are preserved), deduplicated,
and tolerant of deleted entities (a missing id is skipped, never breaks
routing). Steps with no references behave exactly as before.

## Entity classes

Thin subclasses of `Entity` add typed accessors over the settings bag:

- `entities/Network.php` — `currency()`, `statusMap()`, `mapStatus($external)`
- `entities/Source.php` — `paramMap()`, `postbackUrl()`, `costParam()`
- `entities/Offer.php` — `type()`, `url()`, `networkId()`, `payout()`,
  `capDaily()`, `capTotal()`
- `entities/Landing.php` — `type()`, `isRemote()`, `path()`, `url()`,
  `target()`
- `entities/Group.php` — folder grouping

`entities/Repositories.php` is a small factory that returns a cached
`EntityRepository` per entity type and driver, e.g.
`Repositories::offers($db->driver())`.

## Backward compatibility & testing

- All Phase 0 behaviour is unchanged; the five tables are additive.
- Migrations are idempotent and verified to create all tables on a fresh DB and
  to upgrade an existing one.
- New PHPUnit coverage: `tests/FlowEntityResolverTest.php` (offer/landing
  resolution, additive/dedup behaviour, missing-id tolerance, JSON round-trip).
  Full suite: 41 tests passing on PHP 8.2.
