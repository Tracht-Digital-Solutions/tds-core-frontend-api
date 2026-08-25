# AGENTS.md — tds-core-frontend-api

The base frontend API kernel. Read `tds-frontend-contract-pkg`'s AGENTS.md first — this
repo consumes that contract's PHP half (`Module` + `ModuleRegistry`).

## Model

In-process composition, like the gateway: `Modules::enabled()` returns the
extension `Module`s for this build; `Bootstrap` composes them through a
`ModuleRegistry` (dependency-ordered, collision-checked) and mounts their routes.
One PHP-FPM app, no service processes. The base ships the kernel routes
(`/healthz`, `/admin/permissions`, `/wiki.json`, `/me/dashboard-layout`, `/me/preferences`,
`/admin/settings/{ns}`); it MUST boot with zero modules.

> **`/wiki.json` is the admin frontend's API reference, and it is a MERGE.**
> The route list comes from introspecting Slim's `RouteCollector` after
> composition, so it is complete by construction; the prose (summary, params,
> responses, required permission) comes from each module's optional
> `ApiDocSource`, joined on `"<METHOD> <pattern>"`. Built in
> `Service\ApiReference`; the base's own routes are documented in
> `docs/api.php`, since the base is not a `Module`.
> - **Grouping is ownership, not the path.** `ModuleRegistry::routeOwners()`
>   records who mounted what during composition. Before that existed the page
>   grouped by first path segment, which put all thirteen modules' `/admin/*`
>   routes in one bucket called `admin` — the single thing that made it useless
>   as a reference.
> - **An undocumented route still appears** (`documented: false`), and a doc
>   entry whose route no longer exists lands in `stats.orphan_docs` instead of
>   being dropped. `tests/ApiReferenceTest.php` asserts both directions across
>   every composed module, so a renamed path fails the suite.
> - **`vendor/` holds COPIES of the extensions** (Composer mirrors path repos).
>   Editing a sibling `tds-ext-*-pkg` does not change what this service composes
>   until Composer copies it again — re-run `composer update` for that package,
>   or the local test here will keep asserting the old routes. CI is unaffected:
>   the gateway's `_assemble.yml` mirrors from fresh `main` checkouts.

> **`/healthz` must report `db` — the gateway gates on it.** The aggregate health
> flips to 503 when a backend self-reports `db: "down"`/`"no-schema"`; a body
> without the key means "nothing to gate on" (`Support\HealthBody` in the
> gateway). This service shipped without it until 0.11.2, so a frontend pointed
> at a dead or un-migrated database answered `{"ok":true,"status":200}` and the
> whole API looked green while every module route 500'd. `checkDb()` mirrors
> tds-auth-api's two-stage probe: `SELECT 1` for reachability, then
> `SELECT 1 FROM phinxlog` for schema presence — a bare `SELECT 1` succeeds
> against an empty database and would report `ok`. It resolves `PDO` inside the
> try/catch (the container binds it lazily) so bad credentials report `down` with
> HTTP 200 rather than 5xx'ing, and it is **skipped entirely when `DB_NAME` is
> empty**, because booting without a DB is a supported state and reporting
> `down` there would flip the whole gateway to 503 for a service behaving as
> designed.

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
fallback. The **base itself** uses two namespaces: `modules` (Module & Deployment)
and `mail` (SMTP, see *Core services for modules*).

The public page-cache tokens follow that same contract: blog-cms reads
`blog-cms/cache_token` with `BLOG_CACHE_TOKEN` as fallback, and website-cms
reads `website-cms/cache_token` with `WEBSITE_CACHE_TOKEN` as fallback. Both
belong in this service's `.env.example` even though their code lives in composed
extensions; the gateway parity list marks them runtime-configured, so the web
installer deliberately does not ask for either secret.

## Site connections / site keys (`/admin/sites`, `POST /sites/handshake`)

The credential that binds a public static site (landingpage / blog / tools /
auth, plus custom ones) to this API, and the first place the platform records
that a site is connected at all. Before it there were **five disjoint per-site
registers and no key anywhere**: the CORS list knew origins but no sites,
`cms_site` and `blog` knew sites but no origins, tools had one global
`registry_token`, live-chat had a hardcoded slug list, and the four public
origins were enumerated only in a frontend bundle the API could never see.

- **`Service\SiteKeyStore`** (bound as the contract's `SiteKeys`) owns
  `app_site_key` — its own table, not an `app_setting` row, because a site has
  several keys over its life and each carries metadata the panel exists to show.
  Self-bootstrapping DDL like `SettingsStore`, and **only a SHA-256 hash is
  persisted**: the plaintext exists once, in the response of `POST /admin/sites`.
  That also means the store needs **no `SETTINGS_ENCRYPTION_KEY`**, so it works
  on a host where that variable was never set. Verification is one indexed
  lookup on the digest.
- **`Service\SiteKeyPolicy`** (namespace `sites`) holds the enforcement mode and
  the custom-site list, DB-first with `SITE_KEY_ENFORCEMENT` as fallback. Note
  this is **not** the CORS inversion: nothing here can lock an admin out, since
  the panel's own routes are never site-key protected.
- **`Middleware\SiteKeyMiddleware`** gates only the prefixes modules declare
  through the contract's `SiteKeyProtected` (`ModuleRegistry::siteKeyRoutes()`).

Five things about this are load-bearing:

- **Middleware order is `add()` site-keys → auth → CORS.** Slim is LIFO, so that
  runs CORS → auth → site-keys. It must be *inside* CORS (or a 401 has no
  `Access-Control-Allow-Origin` and reports itself to the browser as a CORS
  failure) and *after* auth (or the admin exemption reads an anonymous context
  and never fires, locking the panel out of the CMS preview).
- **The container is touched only after a prefix matches.** Resolving
  `SiteKeys::class` constructs `PDO`, i.e. connects; this middleware sees every
  request, so an unconditional resolve puts a database connection — a hung one
  when the DB is down — in front of the whole API. Same rule the CORS predicate
  follows.
- **Enforcement is three-valued** (`off` default / `warn` / `enforce`), like
  support-tickets' `ingest_mode`. Going straight to `enforce` breaks whichever
  site was forgotten, in production, **invisibly**: the build-time fetch on the
  other end is fail-soft, so a rejected site renders its baked fallbacks and
  reports success. `warn` serves, counts and logs so the gap is a number first.
- **The key travels in a header (`X-TDS-Site-Key`) or the body (`site_key`),
  never the query string** — a credential in an access log, a referrer or
  browser history outlives the request it was sent for. The header is in the
  preflight allow-list even though today's callers are server-side, so the first
  browser use does not die at an OPTIONS.
- **`POST /sites/handshake` is public by necessity.** It runs in the operator's
  browser on the site's own domain before anything is connected — exactly like
  the tools registry sync beside it. It reports the CORS state of the
  **requesting** origin, not the one stored on the key, because on a staging
  host the latter would be confidently wrong.

## Base-service data (per-user preferences)

`GET`/`PUT /me/preferences` hold each authenticated user's **theme, locale and
notification toggles**, keyed by the JWT `userId`. `Domain\UserPreferenceRepository`
owns the `user_preference` table (`user_id`×`pkey`), self-bootstrapping exactly
like the dashboard layout below.

- **Why this exists at all:** the theme lived only in `localStorage`, i.e. per
  browser. That stays — it is the pre-paint cache the no-flash bootstrap reads
  before anything renders — but the server is now the copy that follows the
  choice to another **device**. Reconciling the two is the frontend host's job.
- **Key/value rows, not columns.** A column per preference means a migration
  per preference, and the core has no migrator — the self-bootstrapping DDL
  cannot `ALTER` an existing table. Rows also make an unknown key (a newer
  panel against an older backend) inert rather than fatal.
- **`Support\PreferenceWhitelist` owns the rule, not the route.** A closed key
  AND value set: these land in `<html data-theme>` / `<html lang>`, and an open
  string column feeding a DOM attribute only looks harmless until it is
  rendered somewhere else. It is a separate class because inside the route
  closure it could only be exercised with a live token and a database — which
  is how filtering logic ends up untested. `theme` mirrors tds-shared's
  `THEME_PREFERENCES` and **includes `"system"`**: the browser stores "follow
  the OS" as the *absence* of a value, but the server must hold it as a real
  choice or it cannot be told apart from "never chose".
- **PUT is a PARTIAL write** — keys not sent are left alone, so the Darstellung
  tab saving a theme cannot clear notification toggles it never rendered.
  Unknown keys and invalid values are dropped silently (same convention as the
  dashboard layout); JSON booleans are normalised first, because `(string) false`
  is `""` and would silently turn "switch this off" into "change nothing".
- **Both responses are `Cache-Control: no-store`** — per-user state behind a
  shared gateway must never be handed to the next caller.
- **Best-effort on the client.** `services/frontend/.env` is still an open
  go-live step, so a failing GET has to leave the panel working off
  `localStorage` rather than blocking it.

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

## Live notification feed (`GET /me/notifications`)

The single endpoint the panel shell polls on **every page**. Modules opt in by
implementing the contract's `NotificationSource` (optional, 1.6.0+);
`Service\NotificationFeed` merges them and `Support\NotificationCursor` carries
the per-module cursors.

- **One endpoint, not one per module.** A poller per extension island would be
  thirteen intervals on every page. Joining the feed is a *backend* decision, so
  a new module needs no frontend-host change at all.
- **The cursor is an opaque base64url JSON map** of module id → that module's own
  cursor. Per module, so a module enabled tomorrow is not handed a cursor that
  belonged to another one — it gets its own first call.
- **Every malformed cursor decodes to "first call"**, never a 4xx. The shell
  cannot repair a cursor it did not author, so a 4xx would stall its poller
  permanently; a first call costs the reader one poll of notifications.
- **A first call yields the cursor and no items.** Enforced in the base rather
  than trusted to each source, because the base is the only place that can see
  whether a cursor arrived. Without it every freshly opened tab toasts the
  backlog.
- **A source that throws loses only its round** (no cursor recorded, so its next
  poll is a first call). One broken module must not stop the shell polling.
- Merged oldest-first and capped at `NotificationFeed::MAX_ITEMS` (20), keeping
  the **newest** on overflow.

Since it is polled by every open tab, keep the per-source query cheap — an
indexed `id > cursor` read, not a scan.

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

- **A module must NEVER guard its container bindings with `!$c->has(X::class)`,
  and `ExtensionBindingsTest` is what enforces it.** PHP-DI answers `has()` out
  of its definition sources, and **autowiring is one of them** — so for any
  concrete, instantiable class the answer is always `true`, bound or not. Six of
  the thirteen composed modules opened `register()` with

  ```php
  if ($c !== null && !$c->has(SomeRepository::class)) { $c->set(…); … }
  ```

  and therefore bound **nothing**; the container autowired instead. Where the
  constructor takes only the bound `PDO` that is invisible (identical object),
  which is exactly why it survived so long. Where it takes a **string** it is
  fatal, and only on the routes that resolve it:

  | Module | Entry | Dead route(s) |
  |---|---|---|
  | blog-cms | `TranslationSync` / `RebuildTrigger` | post save · delete · backfill |
  | website-cms | `TranslationSync` / `RebuildTrigger` | block save · delete · backfill |
  | billing | `StripeClient` | `GET /billing/summary` (dashboard widget) |
  | lexware | `LexwareClient` | every Lexware Office call |
  | support-tickets | `ImapTicketIngest` | `POST /tickets/ingest` |
  | tools | `StripeClient` | premium checkout · Stripe webhook |

  The shared symptom was `Parameter $x of __construct() has no value defined or
  guessable` behind a **500**, with reads working perfectly — and, silently, the
  settings-store factories in those closures never ran at all, so the DeepL,
  rebuild, Stripe and Lexware keys typed into *Einstellungen* were being ignored.
  Nothing looked: the extension repos run type-check + build in CI rather than
  tests, `CompositionTest` only asserts that routes are **mounted**, and a PHP-DI
  entry is built lazily, so a broken binding costs nothing until a user saves.
  A module owns the classes it binds and nothing else defines them — bind
  unconditionally.

- **CORS middleware is added AFTER `addRoutingMiddleware()`** (Slim is LIFO, so
  it must be outermost) or OPTIONS preflights get 405'd and browsers block every
  cross-origin request. `tests/PreflightTest.php` guards this through the REAL
  Bootstrap app — never delete it.
- **`Access-Control-Allow-Methods` must list every method a frontend uses.**
  `PATCH` was missing for months and failed in the most confusing way available:
  the preflight is rejected, so the browser never sends the request — the button
  looks dead and the network tab shows an OPTIONS where you are looking for a
  PATCH. The contact inbox's triage is a `PATCH`, and *every* panel call is
  cross-origin (static product host → `api.tracht-digital.de`). `PreflightTest`
  now asserts the whole method list, not just `OPTIONS`.
- **The CORS baseline includes `www.tracht-digital.de`, not only the apex.** The
  canonical landingpage is the apex, but a visitor arriving on `www.` posts the
  contact form from an origin the browser will refuse the response for — and a
  missing `Access-Control-Allow-Origin` is silent: the form shows its generic
  "try again later" and nothing is logged.
- **The allow-list is THREE UNIONED LAYERS, and the union is the safety
  property (0.16.0, `Service\CorsConfig`).** Coded baseline ∪
  `CORS_ALLOWED_ORIGINS` ∪ the rows an admin edits under Einstellungen →
  *CORS / Freigegebene Origins*. It joined the settings store for the reason
  the mail and IMAP moves already established — *a feature that can only be
  configured by editing a file on the host is, on this Plesk host, a feature
  nobody has* — but note the deliberate DIFFERENCE from those two: **this is
  the one namespace where the database does not outrank the env.** Everywhere
  else DB-first exists so an `.env` written at install time cannot shadow the
  form. Here the worse failure runs the other way and is unrecoverable from the
  panel: an admin who removes the origin their own frontend runs on locks the
  panel out of the API, and the only surface that could put it back is the one
  that just stopped working. Union + baseline means no edit made in a browser
  can cost you the browser.
- **The middleware takes a PREDICATE, and checks the free layers first.** It is
  outermost, so it runs on every request including preflights. Resolving the
  settings store unconditionally would put a PDO connect in front of the entire
  API — on a host whose database is down or firewalled that is not a slow
  request but a hung one, and it would hang `/healthz` with it. `corsAllows()`
  answers from baseline + env without touching the database and only consults
  the stored layer for an origin neither covers, which for the first-party
  frontends is never. (This was written the naive way first and the local test
  suite hung on it immediately — a dev `.env` pointing at a stopped MariaDB.)
- **`PUT /admin/cors` normalises, and reports what it refused.** The comparison
  is an exact string match, so `https://kunde.de/` — the standard paste error —
  unblocks nothing, permanently, with no error anywhere to connect it to the
  site that stayed broken. Trailing slashes and host case are normalised; a
  path, a scheme other than http(s), credentials, or `*` are refused *with a
  reason that goes back to the form*. `*` gets its own message because it is the
  natural reach for "allow everything" and would instead break every request:
  the list is served with `Access-Control-Allow-Credentials: true`, where the
  spec forbids the wildcard outright. **`GET /admin/cors`** reports the
  effective list with each entry's layer, the same "what is actually in force"
  role `GET /admin/mail` plays for SMTP — without it the un-deletable baseline
  entries read as a bug.
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
  bootstrap tables (`app_setting`, `user_dashboard_layout`, `user_preference`) still use their own
  `ensureSchema()` — move them to base migrations here when convenient.
- **`php -S` needs `public/router.php`** (built-in server 404s dotted paths).

## Core services for modules

`Bootstrap::container()` binds the services extensions resolve via
`$app->getContainer()->get(...)` — all lazy (boot does no DB/SMTP work):
- **`PDO`** — the shared DB connection (env `DB_*`).
- **`SiteCache`** (frontend-contract, `Service\HttpSiteCache`) — asks a public site to
  re-render the cached HTML of the pages one content change affects. The three public
  sites render on demand behind a file-backed page cache, so a saved block, post or
  guide is invisible until its page is rendered again. Bound here so no extension
  holds an HTTP client, a token or a URL policy of its own — `RebuildTrigger` already
  exists three times near byte-identically, and every fix to it has to be made three
  times.
  > **It never throws and never fails a save.** A site that is down, moved or simply
  > not configured yet must not turn "save this article" into an error: the content is
  > stored either way, the public page stays a little stale, and the panel has a
  > rebuild button to catch up. Failures go to `error_log`.
  >
  > **Three details that are easy to get wrong.** `Content-Type: application/json` is
  > mandatory — the receiving endpoint is an Astro route and its `security.checkOrigin`
  > rejects a form-ish cross-site POST as CSRF, with a message that names neither
  > content types nor the fix. And the timeouts are short on purpose: this runs inside
  > the request that saved the content, so a site that accepts a connection and then
  > hangs would hold the editor's save open until PHP's own limit.
  >
  > **The cache URL is an exact HTTP(S) ORIGIN and redirects are forbidden
  > (0.18.1).** Userinfo, a path, query or fragment makes it unconfigured; a
  > trailing slash is the only tolerated path syntax. The request carries the
  > secret `X-TDS-Cache-Token` as a normal custom curl header, and libcurl
  > reuses custom headers on followed redirects — including a redirect to a
  > different host. `CURLOPT_FOLLOWLOCATION` therefore stays explicitly
  > `false`. An http→https migration must update the stored origin; never
  > restore redirect following as a convenience. `HttpSiteCacheTest` pins both
  > the origin parser and the curl option because the injected transport sits
  > above curl and cannot observe its redirect behavior.
  >
  > Not to be confused with the CMS modules' `RebuildTrigger`, which dispatches a CI
  > build and ships *code*. Both exist because both jobs exist. Operator handbook:
  > `SITES.md`.
- **`Mailer`** (frontend-contract) — SMTP via Symfony Mailer, configured
  **DB-first with `MAIL_DSN` as the env fallback** (`Service\MailConfig`,
  settings namespace `mail`); nothing configured → `NullMailer`
  (`isConfigured()` false). From identity is core-owned
  (`MAIL_FROM`/`MAIL_FROM_NAME`, overridable per host in the store); no
  extension configures its own SMTP.
  > **The stored configuration BEATS `MAIL_DSN`, and that direction is the
  > point (0.15.0).** Until then the mailer was `MAIL_DSN`-only — editable
  > exclusively by hand on the production host — so every notification toggle
  > in the panel switched on a mailer nobody could set up from the panel, and
  > "E-Mail-Benachrichtigungen" silently no-opped on a host whose `.env`
  > predated the feature. Env-first would have kept exactly that: an `.env`
  > written once at install time would permanently shadow the form.
  > Admins configure it under Einstellungen → *E-Mail (SMTP)* (admin product
  > only), which writes `host`/`port`/`security`/`user`/`password`/`from_email`/
  > `from_name` (plus a raw `dsn` escape hatch for transports the form cannot
  > express) through the generic `PUT /admin/settings/mail`.
  > Two routes exist beside it because the generic settings route cannot answer
  > either question: **`GET /admin/mail`** reports the *effective* config
  > (`source: db|env|none`) so an admin does not "fix" a host that mails fine
  > through its `.env`, and **`POST /admin/mail/test`** sends a test mail —
  > SMTP fails in ways a form cannot validate (wrong port, refused relay, bad
  > credentials), and the modules that send do so on events an admin cannot
  > trigger at will. Neither route returns a secret, and an SMTP error is run
  > through `MailConfig::redact()` first because Symfony echoes the DSN — with
  > the password in it — in some failures.
  > A malformed stored DSN degrades to `NullMailer` rather than throwing:
  > resolving the mailer must never 500 an unrelated route.
  > **QUOTE any `.env` value containing a space — `MAIL_FROM_NAME` above all.**
  > `createApp()` calls `Dotenv->load()` before anything else and phpdotenv
  > rejects a bare unquoted spaced value, so `MAIL_FROM_NAME=Tracht Digital
  > Solutions` does not merely break the mail identity — it kills the whole
  > service at boot, surfacing as `"/frontend": {"status": 0}` in the gateway's
  > `/healthz` and `500 Slim Application Error` on every route, with nothing in
  > the app log (the failure precedes the error handler). This shipped in
  > `.env.example` (fixed in 0.11.1) and in the gateway's `install.php` (fixed
  > in tds-gateway-api 0.4.8), so **every fresh install had a dead frontend**.
  >
  > **The env contract is now checked across repos, not remembered (0.14.1).**
  > `.env.example` had never listed `DOCUMENT_ROOT_DIR` / `DOCUMENT_SIGN_SECRET`
  > even though the gateway's `install.php` writes both — the documented setup
  > and the generated one had quietly disagreed since the documents surface
  > moved in here. The installer lives in a *different repo* from the services
  > it configures, so this class of drift is invisible from either side alone.
  > `tds-gateway-api/scripts/check-env-parity.php` now fails the assemble when a
  > service's `.env.example` gains a key `install.php` does not write (or vice
  > versa). Adding an env var here therefore forces a decision over there:
  > either the installer asks for it, or it goes on that script's `DEFAULTED`
  > list with the reason its default is safe.
  >
  > **A composed extension's env vars belong in THIS file too (0.15.1).** The
  > service is the deployment unit, so an extension's settings are the frontend
  > service's settings — `.env.example` now documents the support-tickets
  > mailbox (`TICKET_ADMIN_EMAIL`, `TICKET_UPLOAD_DIR`, `INGEST_TOKEN`,
  > `IMAP_*`, `TICKET_INGEST_*`) that `tds-ext-support-tickets-pkg` has read
  > since it was ported, plus the blog/website page-cache fallbacks
  > (`BLOG_CACHE_TOKEN`, `WEBSITE_CACHE_TOKEN`). All of them are a *fallback*
  > now: the mailbox is configured under Einstellungen → Support-Tickets →
  > E-Mail-Eingang through the runtime settings store, so they are listed in the
  > parity script's `DEFAULTED` table rather than written by `install.php`.
  > **Keep `symfony/mailer` + `symfony/mime` on the same MAJOR as
  > `tds-customer-api`.** In the gateway's in-process mode all services share one
  > PHP process and Composer autoloaders are first-come-first-served per class
  > name, so whichever service is dispatched first wins for every package they
  > share — and the aggregate `/healthz` dispatches customer *before* frontend.
  > This package used to require `^6.4` while customer resolved `^7.4`, i.e. the
  > frontend's Symfony-6-era code was handed Symfony 7 classes at runtime
  > (verified in the Docker stack: the frontend really does load
  > `services/customer/vendor/symfony/mailer/Mailer.php`). Aligned to `^7.4`, so
  > the difference is a patch level rather than a major. `symfony/mime` is now
  > declared explicitly too — `Address`/`Email` are imported directly by
  > `Service\SmtpMailer` and were only ever present as a transitive dependency.
  > See tds-gateway-api#8 for the full divergence list.
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
composer test    # phpunit, 212 tests (27 skip without TDS_TEST_DB_DSN)
```

`tests/ExtensionBindingsTest.php` is the net for the binding trap above. It
reads the `$c->set(Foo::class, …)` calls straight out of every composed module's
source in `vendor/tracht-digital-solutions/*/php/src/*Module.php` — resolving
short names through that file's `use` imports — and asks the booted container
for each one, so a new module or a new binding joins the check by existing
rather than by being listed here. It fails **only** on
`DI\Definition\Exception\InvalidDefinition`, the one error that means "PHP-DI had
no definition it could build"; anything environmental (no database, no
third-party service) is ignored, so the check needs no MariaDB. Where a database
*is* reachable the factories run for real, which is the stronger check — but the
connection is probed once and then stubbed out if it fails, because otherwise
nearly every entry pays a full TCP timeout and a sub-second check takes a minute.

> **One TestCase per file, named after the file.** PHPUnit's directory loader
> only picks up the class whose name matches the filename, so a second TestCase
> living beside another one is **never run** — and reports green while doing it.
> `NotificationCursorTest` and `NotificationRouteTest` are separate files for
> exactly that reason.

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

## The `customer` → `company` rename (dual-accept, 0.14.0)

tds-auth-api renamed its schema and its permission ids in 0.6.0. This service
**reads both spellings for one release**, in two places:

- **The flat claim** — `JwtUserContext::flatCompanyId()` prefers `company_id`
  and falls back to `customer_id`.
- **The act-as header** — `AuthMiddleware::ACT_AS_HEADERS` prefers
  `X-Act-As-Company` and falls back to `X-Act-As-Customer`. Both are in the CORS
  `Allow-Headers`, because a header missing from that list fails the
  **preflight**: the request is never sent, and all you see is an OPTIONS where
  you expected the real call.

Neither is politeness. A token minted five minutes before the deploy carries the
old claim for up to an hour, the panel and the thirteen extensions ship
independently of this service, and none of them deploy at the same instant.
Reading only the new names would silently drop a portal user's tenant — every
scoped list comes back empty, with no error anywhere to explain it.

**Delete both fallbacks together with auth-api's aliases** in the follow-up
release. Leaving them means the old names work forever and the rename bought
nothing.
