# Phase 9: Notifications (Telegram, Webhook, Email)

Phase 9 adds configurable alerts. A **notification channel** is a first-class,
data-driven entity (like Networks, Offers, Rules): you describe *where* a message
goes and *what* it says with `{token}` placeholders; no code per integration.

Channels are triggered by events. The Phase 8 rules engine fires them through a
`notify` action, but the dispatcher is generic and can be reused by other event
producers (e.g. conversions) later.

## Concepts

- **Channel** (`channels` table) — one alert target. `type` selects the
  transport: `telegram`, `webhook` or `email`. All text fields are `{token}`
  templates resolved at send time.
- **Events** — each channel optionally subscribes to a list of event names
  (e.g. `rule`). Empty list = receive every event. A `notify` action can also
  target channels explicitly by id, bypassing the event filter.
- **Message tokens** — when triggered from a rule, the following tokens are
  available: `{event}`, `{rule}`, `{campaign}`, `{time}` plus every metric of
  the rule's window: `{clicks} {uniques} {bots} {blocked} {leads} {purchases}
  {conversions} {revenue} {cost} {profit} {roi} {cr} {epc} {cpc}`.
- **Audit** — every send attempt is recorded in `notification_log`
  (channel, type, event, ok, target, code, error). Secrets such as the Telegram
  bot token are never stored: `target` holds a sanitized destination only.
- **Graceful degradation** — transports use strict timeouts (connect 2s,
  total 3s) and never throw; a failed delivery is logged and the run continues.

## Channel types

### Telegram
- `bot_token` — bot token from @BotFather (secret; never logged).
- `chat_id` — target chat / channel id (tokens allowed).
- `parse_mode` — `HTML` (default), `Markdown` or none.
- `message` — the text to send.

Sends `POST https://api.telegram.org/bot<token>/sendMessage` with
`chat_id` + `text`.

### Webhook
- `url` — endpoint (tokens allowed).
- `method` — `POST` (default) or `GET`.
- `headers` — one per line `Header-Name=value` (tokens allowed).
- `body` — raw body template; if empty, the `message` template is used.
- `content_type` — added automatically for POST when no `Content-Type` header
  is set (default `application/json`).

### Email
- `to` — comma-separated recipients (tokens allowed).
- `from` — From address.
- `subject` — subject template.
- `message` — body template.

Uses PHP `mail()`; ensure the host has a working MTA.

## Triggering from rules

Add a `notify` action to any automation rule (Phase 8). Examples:

```json
[
  {"type": "notify", "event": "rule", "message": "ROI {roi}% on campaign {campaign}"},
  {"type": "notify", "channels": [3, 5]}
]
```

- `event` (optional, default `rule`) — selects channels subscribed to it.
- `channels` (optional) — explicit channel ids; overrides the event filter.
- `message` (optional) — pre-rendered and exposed to channels as `{message}`,
  so a channel template can simply be `{message}`.

The `notify` action is wired in `bin/run_rules.php`, keeping the Phase 8 action
executor decoupled from the notifications module.

## Files

- `entities/Channel.php` — channel entity + typed accessors.
- `notifications/MessageRenderer.php` — pure `{token}` renderer + `flatten()`.
- `notifications/ChannelSender.php` — per-type request builders (pure) + I/O
  transport (overridable for tests).
- `notifications/Notifier.php` — selects channels, sends, audits to
  `notification_log`.
- `db/migrations/012_create_channels.php`, `013_create_notification_log.php`.
- `admin/channels.php` + `channels` schema in `admin/entityschemas.php`.

## Admin

Open **Notifications** in the admin nav to create channels. The form is driven
by the `channels` schema, so adding a field is a data change only.
