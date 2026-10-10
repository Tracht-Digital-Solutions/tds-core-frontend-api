# Per-user data, notifications and time

## Preferences: `GET` / `PUT /me/preferences`

Theme, locale and notification toggles per JWT `userId`. `Domain\UserPreferenceRepository` owns
`user_preference` (`user_id` × `pkey`), self-bootstrapping.

- `localStorage` stays the pre-paint cache; the server copy follows the choice to other devices. The
  frontend host reconciles the two.
- **Key/value rows, not columns:** a column per preference needs a migration, and an unknown key from a newer
  panel stays inert.
- **`Support\PreferenceWhitelist` owns the rule:** a closed key and value set, because values land in
  `<html data-theme>` / `<html lang>`. `theme` mirrors tds-shared's `THEME_PREFERENCES` and includes
  `"system"` (the server must distinguish "follow the OS" from "never chose").
- **PUT is a partial write.** Unknown keys and invalid values are dropped silently; JSON booleans are
  normalised first (`(string) false` is `""`).
- Responses are `Cache-Control: no-store`.
- The client treats this as best effort; a failing GET leaves the panel on `localStorage`.

## Dashboard layout: `GET` / `PUT /me/dashboard-layout`

Each user's widget arrangement, keyed by `userId` (no admin gate). `Domain\DashboardLayoutRepository` owns
`user_dashboard_layout` (`user_id` × `widget_id`, `visible`, `sort`). PUT replaces the whole layout (array
position → `sort`) and validates widget ids against `^[a-z0-9:_-]{1,64}$`. Self-bootstrapping (see
[migrations.md](migrations.md)).

## Live notifications: `GET /me/notifications`

The one endpoint the panel shell polls on every page. Modules opt in via the contract's
`NotificationSource`; `Service\NotificationFeed` merges, `Support\NotificationCursor` carries per-module
cursors.

- **One endpoint**, not one poller per module; joining is a backend decision.
- **The cursor is an opaque base64url JSON map** of module id → that module's cursor, so a newly enabled
  module gets its own first call.
- **Every malformed cursor decodes to "first call"**, never a 4xx (the shell can't repair it).
- **A first call yields the cursor and no items**, enforced in the base.
- **A source that throws loses only its round.**
- Merged oldest first, capped at `NotificationFeed::MAX_ITEMS` (20), keeping the newest.
- Keep per-source queries cheap (an indexed `id > cursor` read); every open tab polls.

## Time zones

`createApp()` pins PHP via `Support\TimeZone::pinPhp()` and the PDO binding calls `pinSession()`, both to
Europe/Berlin, matching production. Every module's `NOW()` / `CURRENT_TIMESTAMP` column holds Berlin
wall-clock time. CLI PHP and CI containers default to UTC. The pin converts nothing; these values are UTC and
have their own readers:

| Value | Written with | Read with |
|---|---|---|
| Pairing `expires_at` | `gmdate()` (`SitePairingService`) | `SitePairingService::utcTimestamp()` |
| Keyless-read counter `first_at` / `last_at` | `gmdate('c')` (`SiteKeyMiddleware`) | ISO 8601 with offset |
| Shop `price_checked_at`, `published_at` | `UTC_TIMESTAMP()` / `gmdate()` | `Support\UtcDateTime` (shop) |
| Shop `withdrawal_consent_at`, `fulfilled_at`, sync `next_call_at` / `locked_until`, `finished_at` | `UTC_TIMESTAMP()` | compared with `UTC_TIMESTAMP()` only |

- **Never compare the two conventions in one condition.**
- Official MySQL/MariaDB images ship empty time-zone tables; a session already at Berlin's offset is left
  alone, any other gets the current offset (`tests/TimeZoneTest`).
- Moving to UTC is a separate cross-module decision, not a refactor.

## Setup wizard (`/me/setup-status`)

`Service\SetupStatus` merges the base items (SMTP, central Stripe) with every module's
`SetupStatusSource`. Admin-only; others get `{items: [], open: 0}`. Choices live in
`user_preference` under `setup:snooze:<id>` (= the token's `auth_time`) and `setup:ignore:<id>`,
written only by `POST /me/setup-status/{id}` — the preferences whitelist does not know them.
"Später" therefore lasts until the next sign-in: `JwtUserContext::sessionStartedAt()` reads
`auth_time`, falling back to `iat` for tokens minted before the auth API carried it.
