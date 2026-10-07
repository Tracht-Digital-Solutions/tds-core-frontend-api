# Migrations

## In-process auto-migrator (`Support\MigrationRunner`)

On the first request after a deploy, `Bootstrap::autoMigrate()` applies every enabled module's pending
migrations via Phinx's PHP `Manager` (no `proc_open`, cron or CLI PHP on the prod host), over all
`registry->migrationPaths()` into **one `phinxlog`**.

- A signature-keyed marker and a non-blocking `flock` make it a cheap single-flight no-op after the first run.
- Failures are logged and swallowed (never fatal) and not marked done, so they retry.
- Gated off when `DB_NAME` is empty (tests, boot) or `AUTO_MIGRATE=0`.
- The gateway proves the run by count during its MySQL 8 rehearsal, because swallowed failures would
  otherwise go unnoticed.

## `preflight()`: three defects that abort every module

Before Phinx touches the set, the runner scans it as text and aborts, naming the file, on:

1. **A file name that doesn't map to its class.** Phinx derives the class (`Util::mapFileNameToClassName`:
   drop the version, `ucwords` on `_`); `20260801000006_live_chat_cta_seed_faq_login.php` ⇒
   `LiveChatCtaSeedFaqLogin`. A mismatch throws while scanning, so no module migrates. Put the module prefix
   **first in both**.
2. **A duplicate class name** (uncatchable fatal redeclaration in one process).
3. **A duplicate version prefix** (one shared `phinxlog`).

In `MigrationRunnerTest`, keep fixtures well-formed apart from the defect under test: with two dirs in the same
version band, the version guard fires first and a class-collision test passes without exercising the class
guard.

## Version bands

Each module owns a distinct date band; a new migration stays in its module's band.

| Module | Band |
|---|---|
| time-tracker | `20260713*` |
| lexware | `20260719000*` (plus later dated files) |
| customers | `20260719100*` |
| billing | `20260719200*` |
| tools | `20260720*` |
| messages, documents, projects | `20260722*` |
| support-tickets | `20260725*` |
| contact-tickets | `20260726*` |
| website-cms | `20260727*` |
| blog-cms | `20260728*` |
| live-chat-cta | `20260801*` |
| shop | `202609*` (`20260907*`–`20260915*`) |
| cards | `20260929*` |

Check the module's `php/db/migrations/` for the exact files before adding one.

## Self-bootstrapped base tables

`app_setting`, `user_dashboard_layout`, `user_preference`, `app_site_key` and the site-connection tables use
an idempotent `CREATE TABLE IF NOT EXISTS` (`ensureSchema()`) once per process instead of base migrations.
Move them to base migrations when convenient.

**That DDL must never run inside a transaction** (see
[site-connections.md](site-connections.md#transactions-and-ddl)).
