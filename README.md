# tds-core-frontend-api

The **base frontend API kernel** (PHP 8 + Slim) — the backend twin of
`tds-core-frontend-pkg`. It composes enabled extension **Modules** in-process
via `tds-frontend-contract-pkg` into one app (the same "one PHP-FPM app, no service
processes" model the gateway already uses), and ships the base kernel routes.

## What it does

- Builds a `ModuleRegistry` from `Modules::enabled()` (the backend twin of the
  frontend product's `astro.config` extension list), composing them
  dependency-ordered with collision checks.
- Mounts every module's routes (`registerAll`).
- Serves the kernel routes: `GET /healthz` (status + composed module list) and
  `GET /admin/permissions` (the merged RBAC catalog contributed by all modules).
- Exposes `Bootstrap::migrationPaths()` for the in-process auto-migrator (ported
  next).

The base MUST boot with **zero** modules — extensions are additive. The admin and
customer API targets differ only in what `Modules::enabled()` returns.

## Develop

```bash
composer install     # path repos → ../tds-frontend-contract, ../tds-ext-time-tracker
composer start        # php -S localhost:8100 -t public public/router.php
composer test         # phpunit
```

`public/router.php` is required for `php -S` (the built-in server 404s dotted
paths without it).

## Status

**Composes all 13 extensions and is deployed via the gateway** (cut over 2026-07-22).
`Modules::enabled()` returns the union both products need — time-tracker, customers,
billing, lexware, tools, messages, projects, documents, support-tickets, contact-tickets,
website-cms, blog-cms, **live-chat-cta**. Ported and live: RS256 JWT/JWKS verify, the admin API
reference (`/wiki.json` — introspected routes joined with each module's `ApiDocSource`
prose, grouped by the module that mounted them),
email (`Mailer`), the in-process auto-migrator, the runtime settings store, per-user
dashboard layout, and the **public content-delivery read surface** (`/content/blog*`,
`/content/topics`, `/content/snippets`, `/content/landing`) the public blog/landingpage
build-fetch. `tests/CompositionTest` validates it end-to-end (all 13 mount and public
routes are unauthenticated).

The base also owns `HttpSiteCache`, which sends content-change events to the
public sites' page-cache endpoint. Cache addresses are exact HTTP(S) origins;
credentials, paths, queries and fragments are rejected. Redirects are never
followed because the request carries `X-TDS-Cache-Token`, and libcurl would
reuse that custom secret header on a redirected request to another host. The
blog and website CMS store their tokens encrypted at runtime; `BLOG_CACHE_TOKEN`
and `WEBSITE_CACHE_TOKEN` are optional `.env` fallbacks for existing hosts.

**Deployment:** this repo has **no CI of its own** — it is bundled as the `frontend`
service by `tds-gateway-api`'s `_assemble.yml` (which checks out this repo + all 13
extension repos and mirrors their Composer `path` packages into `vendor/`). The gateway
routes everything except `/auth` + `/customer` here. Local dev resolves the extensions via
`path` repos; local phpunit is the gate.
