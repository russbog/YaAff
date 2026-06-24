# Phase 10: Multi-user & Role-Based Access Control (RBAC)

Phase 10 turns the single shared-password admin into a multi-user system with
roles and fine-grained permissions — without breaking existing installs. Until
the first user account is created the tracker keeps its original
single-password login and treats the authenticated admin as a super-admin.

## Concepts

- **Permission** — a dot-namespaced string `"{type}.{action}"`, e.g.
  `offers.view`, `offers.manage`, `users.manage`. Permissions are pure data; a
  role is just a list of them. Wildcards:
  - `*` — everything (super-admin)
  - `offers.*` — every action in the `offers` namespace
  - `*.view` — the `view` action across every namespace
- **Role** (`roles` table) — a named, data-driven bundle of permissions. A
  first-class entity, managed like any other (Networks, Offers, ...). Adding a
  permission is editing the list, not changing code.
- **User** (`users` table) — a login account. The login name is the entity
  `name`; everything else lives in the schemaless `settings` bag:
  - `password` — bcrypt hash (`password_hash`); the plaintext never touches the
    database and is never returned to the UI.
  - `role` — role reference (id or name) resolved to permissions at login.
  - `enabled` — master on/off switch (default true).
  - `api_token` — bearer token for the REST API (used in Phase 11).
  - `permissions` — optional extra permissions merged on top of the role's.
- **Effective permissions** = role permissions ∪ the user's extra permissions.
- **Built-in `admin` role** — the name `admin` always grants `*` even if no
  matching role row exists, so the very first user is always functional.

## Architecture (separation of concerns)

- `auth/AccessControl.php` — **pure** permission resolution (wildcard matching).
  No I/O, fully unit-tested.
- `auth/Authenticator.php` — **database-driven**, session-free resolver. Given a
  `DbDriver`, it validates credentials/tokens and produces an immutable session
  context `{id, name, role, permissions}`. Unit-testable with any driver.
- `auth/Auth.php` — the **session/HTTP layer** (plain functions). It is the only
  place that touches `$_SESSION` and emits HTTP responses:
  - `auth_multiuser()` — is at least one account present?
  - `auth_current_user()` — context from the session, or `null` (legacy mode).
  - `auth_attempt($user, $pass)` — validate and start a session.
  - `auth_can($permission)` — boolean check (true in legacy mode).
  - `auth_require($permission, $json=false)` — enforce or emit `403` and stop.

## Backwards compatibility

`auth_can()` / `auth_require()` are **no-ops when no user accounts exist**, so
every Phase 0–9 page and endpoint keeps working unchanged. The moment you create
the first user, `auth_multiuser()` becomes true and:

- the login form requires a username (validated against the `users` table), and
- permission checks become enforced everywhere.

The legacy `adminPassword` login still works only while there are zero users.

## Enforcement points

- **Generic entity API** (`admin/entityapi.php`) — before every action:
  `view` actions need `"{type}.view"`, mutations (`save`/`delete`) need
  `"{type}.manage"`. Password fields are hashed on save and **redacted** (shown
  as `********`) in `list`/`get` responses.
- **Generic entity pages** (`admin/entitypage.php`) — gated by
  `auth_require("{type}.view")`.
- **Navigation** (`admin/header.php`) — each nav item carries a required
  permission; items the user cannot access are hidden.

## Seeded roles

Migration `014_create_roles.php` seeds three starter roles:

| Role    | Permissions                                  |
|---------|----------------------------------------------|
| Admin   | `*`                                          |
| Manager | manage campaigns/offers/landings/sources/... |
| Analyst | `*.view`, `reports.view`, `conversions.view` |

Migration `015_create_users.php` creates the `users` table with **no seed** — an
empty table is what keeps legacy single-password mode active.

## Admin

Open **Users** and **Roles** in the admin nav (visible to users with the
respective permission). Both pages are thin wrappers around the generic entity
admin, driven by the `users` / `roles` schemas in `admin/entityschemas.php`:

1. Create a **Role** with the permissions it should grant.
2. Create a **User**, set a password, pick the role, leave *enabled* checked.
3. From now on login requires username + password and the nav/API enforce the
   user's permissions.

To rotate a password, edit the user and type a new one; leaving the password
field blank keeps the existing hash.

## Files

- `auth/AccessControl.php`, `auth/Authenticator.php`, `auth/Auth.php`
- `entities/Role.php`, `entities/User.php` (+ registered in
  `entities/Repositories.php`)
- `db/migrations/014_create_roles.php`, `015_create_users.php`
- `admin/roles.php`, `admin/users.php`, `roles`/`users` schemas in
  `admin/entityschemas.php`
- Enforcement: `admin/entityapi.php`, `admin/entitypage.php`, `admin/header.php`
- Login: `admin/password.php`, `admin/login.php`
