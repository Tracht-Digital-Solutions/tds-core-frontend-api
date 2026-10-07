# Testing

```bash
composer test    # phpunit; DB-backed tests skip without TDS_TEST_DB_DSN
```

There is no CI here; local phpunit is the gate.

## Key suites

| Suite | Covers |
|---|---|
| `tests/ExtensionBindingsTest.php` | Reads every `$c->set(Foo::class, …)` from each composed module's source (`vendor/tracht-digital-solutions/*/php/src/*Module.php`, resolving short names via `use` imports) and asks the booted container for each. Fails **only** on `DI\Definition\Exception\InvalidDefinition`; environmental errors (no DB, no third party) are ignored. With a reachable DB the factories run for real; the connection is probed once and stubbed if it fails, to avoid a TCP timeout per entry |
| `tests/JwksClientTest.php` | The auth boundary for every module. A real 2048-bit RSA keypair per test and a hand-built JWKS, so forged tokens are really forged. Disk cache: a second `verify()` makes no HTTP call, a cache older than the TTL is refetched, a corrupt cache refetches, a warm cache from another process is honoured, an invalid JWKS response is never written. Mutation check: 10/10 caught, incl. skipping signature verification |
| `tests/PreflightTest.php` | OPTIONS through the real app; the full method list |
| `tests/ApiReferenceTest.php` | Doc ↔ route parity across every composed module |
| `MigrationRunnerTest` | `preflight()` defects (see [migrations.md](migrations.md)) |
| `HttpSiteCacheTest` | Origin parser and `CURLOPT_FOLLOWLOCATION = false` |
| `SitePairingServiceTest` | Pairing; transactional DDL only against a real MySQL 8 |
| `tests/TimeZoneTest` | Session time-zone pinning |
| `NotificationCursorTest`, `NotificationRouteTest` | Feed cursor and route |

## Gotchas

- **One TestCase per file, named after the file.** PHPUnit's directory loader only picks up the class
  matching the filename; a second TestCase in the same file is never run and reports green.
- **Windows:** without `OPENSSL_CONF` (see `tds-auth-api`), `openssl_pkey_new` fails and the JWKS tests
  **skip** rather than run.
- **Time:** run with `php -d date.timezone=Europe/Berlin` when touching time; CLI PHP defaults to UTC and
  hides offset bugs.
- **`composer install` can't run from a git worktree**; copy `vendor/` from the main checkout.
