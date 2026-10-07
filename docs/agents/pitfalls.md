# Pitfalls (carried from the four APIs)

## A module must never guard its bindings with `!$c->has(X::class)`

PHP-DI answers `has()` from its definition sources, and **autowiring is one of them**, so for any concrete
class the answer is always `true`. Six modules once opened `register()` with

```php
if ($c !== null && !$c->has(SomeRepository::class)) { $c->set(…); … }
```

and bound **nothing**; the container autowired. Where a constructor takes only the bound `PDO` that is
invisible. Where it takes a string it is fatal on exactly the routes that resolve it
(`Parameter $x of __construct() has no value defined or guessable` → 500, reads working fine), and the
settings-store factories inside those closures never ran, so keys typed into *Einstellungen* were ignored.
Affected then: blog-cms and website-cms (saves, deletes, backfill), billing (dashboard widget), lexware (every
call), support-tickets (IMAP ingest), tools (premium checkout, Stripe webhook).

A module owns the classes it binds; bind unconditionally. `tests/ExtensionBindingsTest.php` enforces it (see
[testing.md](testing.md)). `has()` on an **interface** the core may or may not bind (e.g. `SiteCache`) is fine.

## CORS

- **Add CORS after `addRoutingMiddleware()`** (Slim is LIFO; it must be outermost) or every OPTIONS preflight
  is 405'd. `tests/PreflightTest.php` guards it through the real `Bootstrap`; never delete it.
- **List every method and header a frontend uses** in the CORS response. A missing one fails the preflight:
  the request is never sent, and the network tab shows only an OPTIONS.
- More in [settings-and-services.md](settings-and-services.md#cors-servicecorsconfig).

## Env

- `env()` uses explicit `?? false` checks, never `$_ENV[$k] ?? getenv($k) ?: $default` (`??` binds tighter
  than `?:` and clobbers `"0"` / `""`). See `Bootstrap::env()`.
- Quote `.env` values containing spaces.

## Migrations

Class names, version prefixes and file-name mapping must be unique and correct across **all** modules; see
[migrations.md](migrations.md).

## Tooling

- **`php -S` needs `public/router.php`** (the built-in server 404s dotted paths).
- **`composer install` can't run inside a git worktree**: the path repo `../tds-frontend-contract-pkg`
  resolves relative to the checkout root. Copy `vendor/` from the main checkout.
- **Edits to a sibling extension aren't seen** until `composer update` copies it into `vendor/` again.
