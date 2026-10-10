# Architecture

## In-process composition

`Modules::enabled()` returns the extension `Module`s for this build; `Bootstrap` composes them through a
`ModuleRegistry` (dependency-ordered, collision-checked) and mounts their routes. One PHP-FPM app, no
service processes. It **must boot with zero modules**.

Enabled modules (the union both products need): time-tracker, customers, billing, lexware, tools,
messages, projects, documents, support-tickets, contact-tickets, live-chat-cta, website-cms, blog-cms,
shop, cards, analytics, referrals.

## Base routes

| Area | Routes |
|---|---|
| Health | `GET /healthz` |
| Reference | `GET /wiki.json`, `GET /admin/permissions` |
| User data | `GET` / `PUT /me/preferences`, `GET` / `PUT /me/dashboard-layout`, `GET /me/notifications` |
| Settings | `GET` / `PUT /admin/settings/{ns}`, `GET /admin/mail`, `POST /admin/mail/test`, `GET /admin/stripe`, `POST /admin/stripe/test`, `GET` / `PUT /admin/cors` |
| Sites | `GET` / `POST` / `PUT /admin/sites`, `DELETE /admin/sites/{id}`, `POST /sites/handshake`, `POST /sites/pairings/exchange`, `POST /sites/pairings/finalize`, `GET /content/sitemap-exclusions` |
| Modules | `GET /admin/modules` (installed `tracht-digital-solutions/*` Composer versions) |

The base's own routes are documented in `docs/api.php` (the base isn't a `Module`).

## `/wiki.json`: the admin API reference is a merge

The route list comes from introspecting Slim's `RouteCollector` after composition (complete by
construction); the prose comes from each module's optional `ApiDocSource`, joined on
`"<METHOD> <pattern>"`. Built in `Service\ApiReference`.

- **Grouping is ownership, not the path** (`ModuleRegistry::routeOwners()`); grouping by first segment
  put every module's `/admin/*` routes in one bucket.
- An undocumented route still appears (`documented: false`); a doc entry without a route lands in
  `stats.orphan_docs`. `tests/ApiReferenceTest.php` asserts both directions across every module.
- **`vendor/` holds copies of the extensions** (Composer mirrors path repos). Editing a sibling
  `tds-ext-*-pkg` changes nothing here until `composer update` for that package.

## Public content

Modules serve the public reads without authentication (`AuthMiddleware` is non-gating): blog-cms
(`/content/blog*`, `/content/topics`, `/content/snippets`), website-cms (`/content/landing`,
`/content/legal*`), tools, shop and cards. The public sites fetch them server-side through the gateway's
catch-all. Only published content leaks, and every one degrades to an empty payload on a DB error.

## Auth: `UserContext`

`AuthMiddleware` verifies the RS256 JWT (`Auth\JwksClient` against `tds-auth-api`'s JWKS) and binds
`UserContext` (`Support\JwtUserContext`): admin, user id, multi-company claims and the act-as header →
`isAdmin`, `userId`, `permissions`, `activeCompanyId`.

- It is **non-gating**: it sets the principal (JWT or anonymous) and routes enforce their own auth.
- It rebinds `UserContext` per request (safe in the one-request-per-worker model).
- Unset `AUTH_API_URL` → no verifier → every request anonymous (boot and dev work without auth-api).
- **Modules read `UserContext`; they never verify tokens.**

### `customer` → `company` dual-accept

For one transition release: `JwtUserContext::flatCompanyId()` prefers `company_id` and falls back to
`customer_id`; `AuthMiddleware::ACT_AS_HEADERS` prefers `X-Act-As-Company` over `X-Act-As-Customer`. Both
headers are in CORS `Allow-Headers` (a missing header fails the preflight). Reading only the new names
would silently empty every scoped list. **Delete both fallbacks together with auth-api's aliases.**

## `/healthz` reports `db`

The gateway flips to 503 on `db: down` / `no-schema`; a body without `db` means "nothing to gate on".
`checkDb()` runs `SELECT 1` for reachability, then `SELECT 1 FROM phinxlog` for schema (a bare `SELECT 1`
succeeds against an empty DB). It resolves `PDO` inside try/catch, so bad credentials report `down` with
HTTP 200, and it is **skipped when `DB_NAME` is empty** (booting without a DB is supported).

## Enabling a module

1. Add `new SomeModule()` to `Modules::enabled()`.
2. Add the extension's Composer package (path repo locally; the gateway's `_assemble.yml` checks out the
   sibling and mirrors it into `vendor/`).
3. Give it its own migration band (see [migrations.md](migrations.md)).

The registry throws on a duplicate id, missing dependency, cycle or duplicate permission key.

## Deployment

The gateway's `_assemble.yml` checks this repo out as the `frontend` service with all extension repos,
mirrors path packages into `vendor/` (`COMPOSER_MIRROR_PATH_REPOS=1`) and bundles it under
`services/frontend/`. The in-process auto-migrator brings the schema up on the first request after a
deploy. The gateway's `composer-audit.php` is the only dependency audit of this bundle.

## Module inventory

`GET /admin/modules` (admin) returns the installed `tracht-digital-solutions/*` Composer package versions
of this bundle. Releases and deploys are CI-owned; there is no runtime update or dispatch from here.
