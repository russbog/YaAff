# Phase 11 — REST API + OpenAPI

A generic, token-authenticated REST API exposing full CRUD over every
first-class entity (offers, landings, sources, networks, domains, integrations,
rules, channels, users, roles). It reuses the same persistence layer as the
admin UI, so both behave identically.

## Architecture

The CRUD logic lives in one place and is shared by the admin endpoint and the
REST API:

```
admin/entityapi.php ─┐
                     ├─> EntityService ─> EntityRepository ─> DbDriver
api/rest.php ────────┘        (schema-driven coercion, password hashing,
                              secret redaction, group resolution)
```

- **`entities/EntityService.php`** — schema-driven CRUD. `list/get/save/delete`.
  Coerces posted values per the field type declared in
  `admin/entityschemas.php`, hashes `password` fields, redacts secrets on read,
  resolves group names. Throws `EntityServiceException` (carrying an HTTP
  status) on bad input.
- **`api/RestApi.php`** — pure dispatcher. Maps HTTP method + path to an
  `EntityService` call and enforces permissions via `AccessControl`. No
  transport concerns → fully unit-testable.
- **`api/ApiAuth.php`** — resolves a bearer token to a permission context.
- **`api/rest.php`** — thin HTTP entry (parses path/body, emits JSON).
- **`api/OpenApiBuilder.php` / `api/openapi.php`** — generates an OpenAPI 3.0
  document from the entity schemas (always in sync, no hand-kept duplicate).
- **`admin/api.php`** — in-admin Swagger UI rendering the generated spec.

## Authentication

Every request needs `Authorization: Bearer <token>` (or `?token=` fallback).
Two token sources:

1. **Master token** — `apiToken` in `settings.php`. When set it grants full
   super-admin access (permissions `["*"]`), mirroring the legacy single-password
   model for machine access. Leave empty to disable.
2. **Per-user token** — the `api_token` field on a User entity (Phase 10). The
   request inherits that user's effective permissions (role ∪ extra).

Unauthenticated requests get `401`.

## Routes

Base path `/api/rest.php`. Path style uses `PATH_INFO`; a `?type=&id=` query
fallback is also accepted.

| Method | Path              | Action        | Permission       |
|--------|-------------------|---------------|------------------|
| GET    | `/<type>`         | list          | `<type>.view`    |
| GET    | `/<type>/<id>`    | get one       | `<type>.view`    |
| POST   | `/<type>`         | create        | `<type>.manage`  |
| PUT    | `/<type>/<id>`    | replace       | `<type>.manage`  |
| PATCH  | `/<type>/<id>`    | partial update| `<type>.manage`  |
| DELETE | `/<type>/<id>`    | delete        | `<type>.manage`  |

PATCH merges the supplied fields onto the existing entity; PUT/POST take the
full field set (missing fields fall back to schema defaults).

### Responses

- Success: `{"ok": true, ...}` (`items`, `item`, `id`, or `deleted`).
- Error: `{"ok": false, "error": "..."}` with status `400/401/403/404/405/422`.
- Create returns `201`.
- `password` fields are write-only: never returned, shown as `********`.

## Examples

```bash
# List offers
curl -H "Authorization: Bearer $TOKEN" https://host/api/rest.php/offers

# Create an offer
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"My Offer","payout":"12.5","currency":"USD"}' \
  https://host/api/rest.php/offers

# Update one field
curl -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"payout":"15"}' https://host/api/rest.php/offers/3

# Delete
curl -X DELETE -H "Authorization: Bearer $TOKEN" https://host/api/rest.php/offers/3

# OpenAPI spec
curl https://host/api/openapi.php
```

## Notes

- The API is data-driven: adding a field to a schema automatically extends the
  API and the OpenAPI document — no code changes.
- Errors from external systems never affect the API; all failures are local and
  deterministic.
- The Swagger UI page (`admin/api.php`) loads the spec from `api/openapi.php`.
