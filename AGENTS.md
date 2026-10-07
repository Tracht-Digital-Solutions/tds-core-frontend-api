# AGENTS.md — tds-core-frontend-api

The **composed frontend API kernel**: one PHP-FPM app that composes every extension's `Module`
(via `tds-frontend-contract-pkg`'s `ModuleRegistry`) and serves the base routes. The gateway routes
everything except `/auth` and `/customer` here. It serves both panel products and the public sites.
It has no CI of its own; local phpunit is the gate, and the gateway's `_assemble.yml` is its
deploy pipeline.

Read `tds-frontend-contract-pkg/AGENTS.md` first.

## Commands

```bash
composer install        # not from inside a git worktree (path repos resolve from the checkout root)
composer start          # php -S localhost:8100 with public/router.php
composer test           # phpunit; DB tests need TDS_TEST_DB_DSN; Windows needs OPENSSL_CONF
php -d date.timezone=Europe/Berlin vendor/bin/phpunit   # when touching time
```

## Hard rules

- It must boot with **zero modules** and without a database.
- Modules bind container entries unconditionally (`ExtensionBindingsTest` enforces it).
- Modules read `UserContext`; they never verify a token themselves.
- `/healthz` reports `db`; the gateway gates on it.
- CORS is added after `addRoutingMiddleware()`, lists every used method and header, and is a union of baseline, env and panel rows.
- Middleware order is `add()` site-keys → auth → CORS. Touch the container only after a prefix matches.
- Migration class, version and file name are unique across all modules; each module stays in its band.
- Never run DDL inside a transaction; read `gmdate()` values back as UTC.
- An env var added here needs a decision in the gateway's `install.php` or `check-env-parity.php`.
- Quote any `.env` value containing a space.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing composition, base routes, auth, health or enabling a module |
| [docs/agents/settings-and-services.md](docs/agents/settings-and-services.md) | Touching the settings store, mail, Stripe, CORS or `SiteCache` |
| [docs/agents/site-connections.md](docs/agents/site-connections.md) | Touching site keys, pairing, handshake or sitemap exclusions |
| [docs/agents/user-data.md](docs/agents/user-data.md) | Touching preferences, dashboard layout, notifications or time handling |
| [docs/agents/migrations.md](docs/agents/migrations.md) | Adding a migration, a module or a self-bootstrapped table |
| [docs/agents/pitfalls.md](docs/agents/pitfalls.md) | Any change; the traps carried from the four APIs |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or running tests |

Operator handbook for the public sites: [SITES.md](SITES.md). Base route docs: `docs/api.php`.
Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
