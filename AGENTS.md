# AGENTS.md — tds-core-frontend-api

The base frontend API kernel. Read `tds-frontend-contract-pkg`'s AGENTS.md first — this
repo consumes that contract's PHP half (`Module` + `ModuleRegistry`).

## Model

In-process composition, like the gateway: `Modules::enabled()` returns the
extension `Module`s for this build; `Bootstrap` composes them through a
`ModuleRegistry` (dependency-ordered, collision-checked) and mounts their routes.
One PHP-FPM app, no service processes. The base ships the kernel routes
(`/healthz`, `/admin/permissions`, `/wiki.json`, `/me/dashboard-layout`,
`/admin/settings/{ns}`); it MUST boot with zero modules.

`Modules::enabled()` currently composes **all 13** extensions (the union both
products need) so this single backend serves both the admin and customer
frontends: time-tracker, customers, billing, lexware, tools, messages, projects,
documents, **support-tickets** (`/tickets`, `/admin/tickets`), **contact-tickets**
(`/contact`), **live-chat-cta** (`/live-chat-cta/*` public + `/admin/live-chat-cta/*`),
**website-cms** (`/cms/*`), **blog-cms** (`/blogs/*`, `/blog/*`).
The four CMS/ticket extensions replaced the archived content/contact backends and
serve the public blog/landingpage build-fetch + the admin CMS/ticket UIs;
live-chat-cta backs the floating support widget (`LiveChatCta` island in
tds-shared-pkg). All were added once their migration version prefixes were made
globally unique (see below).

**Public content delivery** is the successor to tds-content-api's open read, served
by two of those modules as their only UNAUTHENTICATED routes (`AuthMiddleware` is
non-gating, so a route with no self-gate is public): blog-cms serves
`/content/blog`, `/content/blog/popular`, `/content/blog/{slug}`, `/content/topics`,
`/content/snippets`; website-cms serves `/content/landing`. Only published content
leaks, and every one degrades to an empty payload on a DB error (build-fetch
fail-safe). The public blog + landingpage SSG builds fetch these at build time
through the gateway's catch-all — their existing `.../content` base URL is
unchanged, so no frontend edit was needed.

## Runtime settings store

`Service\SettingsStore` (bound in the container, resolvable by modules via the contract `SettingsStore` interface) is a
namespaced key/value store so third-party config (DeepL keys, rebuild tokens, …)
is frontend-editable instead of `.env`-only. **Read pattern for consumers: DB-first
with env fallback** — a non-empty stored value wins, else the env var, else a
coded default. **Secrets are AES-256-GCM-encrypted at rest** under
`SETTINGS_ENCRYPTION_KEY` (`v1:base64(iv|tag|cipher)`); the admin API
(`GET`/`PUT /admin/settings/{ns}`, admin-only) returns only masked state
(`configured` + `last4`), and a blank secret on save means "keep existing". The
`app_setting` table (`namespace`×`skey`, `svalue`, `is_secret`) **self-bootstraps**
(no migrator yet — same as the dashboard-layout table). Namespaces are per-extension
(`blog-cms`, `website-cms`, …) so keys don't collide in the shared table. An
extension adopts it by resolving `SettingsStore` from the container (or reading the
shared `app_setting` table via the core PDO); the DeepL/rebuild env vars stay the
fallback.

## Base-service data (per-user dashboard layout)

`GET`/`PUT /me/dashboard-layout` persist each authenticated user's dashboard
widget arrangement (which widgets show + order), keyed by the JWT `userId` — no
admin gate, a user manages their own. `Domain\DashboardLayoutRepository` owns the
`user_dashboard_layout` table (`user_id`×`widget_id`, `visible`, `sort`). PUT
replaces the whole layout (order = array position → `sort`), validating widget ids
against `^[a-z0-9:_-]{1,64}$`. **The core has no Phinx migration runner yet** (it
lands with the assemble pipeline), so this base table **self-bootstraps**: an
idempotent `CREATE TABLE IF NOT EXISTS` runs once per process. When the migrator
lands, move that DDL into a base migration and drop `ensureSchema()`.

## Module inventory + updates (`/admin/modules/*`)

The backend of the panel's Module page. Three admin-only routes:

| Route | Does |
|---|---|
| `POST /admin/modules/check` | Looks up `dist-tags.latest` for the posted packages (`Service\PackageRegistry`), returns the deploy targets, the installed **Composer** versions of this bundle, and the automation state. Also **stores the posted inventory** — see below. |
| `POST /admin/modules/deploy` | `workflow_dispatch` on one configured target (`Service\WorkflowDispatcher`). 202 on success, **502** when GitHub refused — the request was fine, the upstream was not. |
| `POST /admin/modules/auto-update` | Runs the unattended check now, `force`d (so an admin can try the wiring before switching automation on). |

**Why the API proxies the registry.** GitHub Packages needs a `read:packages`
token even for public packages, and that token must never reach the browser.
`PackageRegistry` therefore hard-restricts lookups to
`@tracht-digital-solutions/*` — without that allow-list the check route is a
generic outbound HTTP proxy for anyone who reaches it (the classic SSRF shape).

**Why POST for a read.** The composed package set is a property of the *frontend*
build; this API cannot know it. The panel posts its build-time inventory
(`{pkg, installed, range}`) and that is also what makes unattended updates
possible at all — the pinned ranges live in the product's `package.json`, which
this service never sees. Automatic updates therefore start working once an admin
has opened the Module page once. That bootstrap is deliberate: the alternative is
this service guessing at another repo's pins.

Config lives in settings namespace **`modules`** (`Service\ModuleUpdateConfig`,
DB-first with env fallback, both tokens encrypted). An unset `dispatch_token`
falls back to `registry_token` — one PAT usually carries both scopes. The whole
resolver is wrapped in try/catch because a host with **no database** is exactly
when an admin opens this page; a throw there would 500 the one screen that
explains the problem.

### Unattended updates (`Service\AutoUpdater`)

There is no cron and no `proc_open` on the prod host, so this piggybacks on
request traffic the same way the auto-migrator does: **one file read per
request** (`var/auto-update/next-run`) and real work only once per configured
interval, with the marker claimed *before* the slow part so concurrent requests
cannot dispatch the same deploy twice. The honest consequence: **an API that
receives no traffic performs no automatic updates.**

Two hard limits, both asserted in `AutoUpdaterTest`:

- **Frontend target only.** The backend target re-assembles the bundle from every
  service's and extension's `main`, which would ship whatever is merged but
  unreleased. Never a decision to take unattended.
- **In-range versions only.** A newer version outside the pin needs a repin commit
  in the product repo; dispatching for one would fire a deploy every interval and
  change nothing. `Service\VersionRange` is the **PHP twin of the host's
  `lib/moduleUpdates.ts`** — same 0.x caret rule, maintained by hand like the
  Zod/PHP validator pairs. Change one, change the other. Note `satisfies()`
  returns **null** for a range it cannot parse: only an explicit `true` is
  permission to deploy.

Prereleases sort below their release, deliberately — every package repo publishes
a `@dev` prerelease on each push to `main`, and treating those as newer would
make the updater deploy continuously.

## Load-bearing gotchas (carried from the four APIs)

- **CORS middleware is added AFTER `addRoutingMiddleware()`** (Slim is LIFO, so
  it must be outermost) or OPTIONS preflights get 405'd and browsers block every
  cross-origin request. `tests/PreflightTest.php` guards this through the REAL
  Bootstrap app — never delete it.
- **`env()` uses explicit `?? false` checks**, never
  `$_ENV[$k] ?? getenv($k) ?: $default` (`??` binds tighter than `?:`, clobbering
  "0"/""). See `Bootstrap::env()`.
- **Migration class names must be globally unique** across every module (the
  in-process auto-migrator includes them all into one process). Each extension
  prefixes with its module id; the base only aggregates the paths. **Migration
  *versions* (the numeric filename prefix) must also be unique across extensions**
  — they share ONE `phinxlog`, so a duplicate version makes Phinx throw.
- **…and each migration's FILE name must map to its class name.** Phinx derives the
  expected class from the file name (`Util::mapFileNameToClassName`: drop the version
  prefix, `ucwords` on `_`), so `20260801000006_live_chat_cta_seed_faq_login.php` ⇒
  `LiveChatCtaSeedFaqLogin`. A mismatch throws `InvalidArgumentException: Could not
  find class …` **while the set is being scanned**, i.e. before anything is applied —
  so one badly-named file in one extension means **no extension migrates at all** and
  every route 500s on a fresh DB. That is exactly what `tds-ext-live-chat-cta-pkg`
  (5 files) and `tds-ext-tools-pkg` (2) shipped with until 2026-08-04: module-prefixed
  classes (`LiveChatCtaCreateFaq`) behind verb-first file names (`create_live_chat_cta_faq`).
  Put the module prefix **first in both**. **The runner now catches this before Phinx
  runs** (`preflight()`, since 0.10.1) — it derives the expected class from the file
  name and reports `'<file>' declares class 'X' but Phinx expects 'Y'`. Until then only
  an actual `phinx migrate` surfaced it, which is why it shipped undetected for weeks.
- **In-process auto-migrator (`Support/MigrationRunner`).** On the first request
  after a deploy, `Bootstrap::autoMigrate()` applies every enabled extension's
  pending migrations via Phinx's PHP `Manager` (no `proc_open`/cron/CLI php — the
  prod host has none), over all `registry->migrationPaths()` into one `phinxlog`.
  A signature-keyed marker + non-blocking `flock` make it a cheap single-flight
  no-op after the first run; a failure is logged and swallowed (never fatal), and is
  not marked done so it retries.
  **`preflight()` scans the composed set as TEXT before Phinx touches it** and aborts on
  the three defects that would otherwise kill the whole run: a file name that does not map
  to its class, a duplicate class name (an uncatchable fatal redeclaration once two files
  declare into one process), and a duplicate version prefix (one shared `phinxlog`). All
  three abort **every** extension, not just the offender — so the message names the file.
  Keep the fixtures in `MigrationRunnerTest` well-formed apart from the one defect under
  test: with two dirs on the same default version band, the version guard fires first and a
  class-collision test passes without ever exercising the class guard. **Gated
  off when `DB_NAME` is empty (tests/boot) or `AUTO_MIGRATE=0`.** Base self-
  bootstrap tables (`app_setting`, `user_dashboard_layout`) still use their own
  `ensureSchema()` — move them to base migrations here when convenient.
- **`php -S` needs `public/router.php`** (built-in server 404s dotted paths).

## Core services for modules

`Bootstrap::container()` binds the services extensions resolve via
`$app->getContainer()->get(...)` — all lazy (boot does no DB/SMTP work):
- **`PDO`** — the shared DB connection (env `DB_*`).
- **`Mailer`** (frontend-contract) — SMTP via Symfony Mailer when `MAIL_DSN` is set,
  else `NullMailer` (`isConfigured()` false). From identity is core-owned
  (`MAIL_FROM`/`MAIL_FROM_NAME`); no extension configures its own SMTP.
- **`UserContext`** (frontend-contract) — the request principal, populated by
  `AuthMiddleware` from the verified RS256 JWT (`Auth\JwksClient` against
  tds-auth-api's JWKS). Maps admin/uid + the multi-company claims + the
  `X-Act-As-Customer` header to `isAdmin`/`userId`/`permissions`/`activeCompanyId`
  (see `Support\JwtUserContext`). Auth is centralized here — **modules read the
  UserContext, never verify a token themselves**.

`AuthMiddleware` is **non-gating**: it sets the principal (Jwt or anonymous) and
hands off; routes/modules enforce their own auth via the context (a
RequirePermission middleware or in-action checks). It rebinds `UserContext` on the
shared container per request — safe in the in-process (one-request-per-worker)
model. Unset `AUTH_API_URL` → no verifier → every request anonymous (boot/dev
works without auth-api).

## Enabling a module

Add `new SomeModule()` to `Modules::enabled()` and add the extension's Composer
package (path repo for local dev; the gateway's `_assemble.yml` checks out the
sibling repo + mirrors it into `vendor/` for the bundle). The registry throws on
a duplicate id / missing dep / cycle / duplicate permission key.

**Migration version prefixes must be globally unique across ALL composed modules.**
Every module's migrations merge into ONE shared `phinxlog` here, and Phinx fatals
on duplicate numeric versions (not just duplicate class names). Each module owns a
distinct date band — time-tracker `20260713*`, lexware `20260719000*`, customers
`20260719100*`, billing `20260719200*`, tools `20260720*`, messages/projects/
documents `20260722*`, support-tickets `20260725*`, contact-tickets `20260726*`,
website-cms `20260727*`, blog-cms `20260728*`, live-chat-cta `20260801*`. A new migration stays in its
module's band. (The four CMS/ticket modules were renumbered off overlapping
`20260714*`/`20260718*` prefixes when they were composed in.)

## Deployment

The **assemble/deploy pipeline is the gateway's** (`tds-gateway-api`
`_assemble.yml`): it checks out this repo as the `frontend` service + all its
extension repos, mirrors the Composer `path` packages into `vendor/`
(`COMPOSER_MIRROR_PATH_REPOS=1`), and bundles it under `services/frontend/`. The
gateway routes everything except `/auth` + `/customer` here (the default
catch-all). This repo still has **no CI of its own** — local phpunit is the gate.
The in-process auto-migrator (above) brings the schema up on the first request
after deploy.

## Tests

```bash
composer test    # phpunit, 58 tests
```

`tests/JwksClientTest.php` covers the kernel's **auth boundary**. Every composed
module trusts `UserContext` and never re-verifies a token, so this class is the
single place where "is this caller who they say they are" is decided for the
whole frontend API. A real 2048-bit RSA keypair is generated per test and the
JWKS is hand-built from it, so a forged token is genuinely forged.

The half that is easy to get wrong is the **disk cache**, whose two failure
modes point in opposite directions:

- **too little caching** hammers tds-auth-api on every request that carries a
  token — which is all of them;
- **too much** keeps trusting a key that has been rotated out.

Both are pinned (a second `verify()` makes no HTTP call; a cache older than the
TTL is refetched), along with the recovery paths: a corrupt or truncated cache
file refetches rather than bricking auth, a warm cache written by an earlier
process is honoured, and an **invalid JWKS response is never written to disk** —
caching garbage would keep auth broken for the whole TTL.

Verified by mutation: 10 deliberate breakages introduced, 10 caught — including
replacing `JWT::decode` with a bare base64 payload read, i.e. skipping signature
verification altogether.

> The Windows `OPENSSL_CONF` gotcha documented in `tds-auth-api` applies here
> too: without it `openssl_pkey_new` fails and these tests **skip** rather than
> run.
>
> Note also that `composer install` cannot run from inside a git worktree — the
> `path` repo (`../tds-frontend-contract-pkg`) resolves relative to the checkout
> root. Copy `vendor/` from the main checkout instead.

## After a change

Bump `version` in `composer.json`, update this file + README, commit together.
