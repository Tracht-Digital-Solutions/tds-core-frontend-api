# Site connections, site keys and pairing

The credential that binds a public site (landing page, blog, tools, shop, auth, cards, or a custom one) to
this API, and the platform's record that a site is connected. Operator handbook: [SITES.md](../../SITES.md).

## Components

- **`Service\SiteKeyStore`** (bound as the contract's `SiteKeys`) owns `app_site_key`: its own table, since a
  site has several keys over its life with metadata the panel shows. Self-bootstrapping DDL. **Only a
  SHA-256 hash is persisted**; the plaintext exists once, in the `POST /admin/sites` response. It needs no
  `SETTINGS_ENCRYPTION_KEY`. Verification is one indexed lookup.
- **`Service\SiteKeyPolicy`** (namespace `sites`) holds the enforcement mode and custom sites, DB first with
  `SITE_KEY_ENFORCEMENT` as fallback. Nothing here can lock an admin out; panel routes are never site-key
  protected.
- **`Service\SitemapExclusions`** (namespace `sites`, key `sitemap_exclusions`) holds per-site paths a site
  drops from its sitemap and serves `noindex`. JSON in the settings store (no migration needed). Fail-soft:
  no store means "nothing excluded", never "everything". Public read `GET /content/sitemap-exclusions`;
  admin via `GET` / `PUT /admin/sites`. A `PUT` replaces the whole map.
- **`Service\SiteConnectionStore`, `Service\SitePairingService`** (+ `PairingRateLimiter`) implement the
  contract's `SiteConnections`: pairing invitations, exchange and finalisation.
- **`Middleware\SiteKeyMiddleware`** gates the prefixes modules declare via `SiteKeyProtected`
  (`ModuleRegistry::siteKeyRoutes()`) plus the core's `/content/sitemap-exclusions`, appended where the
  middleware is added.

## Load-bearing rules

- **Middleware order is `add()` site-keys → auth → CORS** (Slim LIFO runs CORS → auth → site-keys). Inside
  CORS, so a 401 still carries `Access-Control-Allow-Origin`; after auth, so the admin exemption sees the
  real principal (the CMS preview).
- **The container is touched only after a prefix matches.** Resolving `SiteKeys` connects to the DB; doing it
  for every request would put a (possibly hung) connection in front of the whole API.
- **Enforcement is three-valued:** `off` (default) / `warn` / `enforce`. Going straight to `enforce` breaks a
  forgotten site invisibly (its fetch is fail-soft). `warn` serves, counts and logs; the keyless-read counter
  stores `first_at` / `last_at` as ISO 8601 with offset.
- **The key travels in `X-TDS-Site-Key` or the body (`site_key`), never the query string.** The header is in
  the preflight allow-list.
- **`POST /sites/handshake` is public by necessity**: it runs in the operator's browser on the site's domain
  before anything is connected, and reports CORS for the **requesting** origin.

## Pairing

- `POST /sites/pairings/exchange`: the site's server exchanges the ten-minute token it received at
  `POST /tds/connect`. The token is never accepted in a query string; only its SHA-256 exists in the DB.
- `POST /sites/pairings/finalize`: phase two activates the pending key only after the site has written its
  private connection file. Repeating it is safe and returns the active connection.

### Transactions and DDL

**Never run DDL inside a transaction.** MySQL commits implicitly on any DDL (even
`CREATE TABLE IF NOT EXISTS` for an existing table), and PHP 8's `commit()` then throws "There is no active
transaction". The schema flag is per process, so every request starts without it. Prepare schemas before
`beginTransaction()`: `SiteConnectionStore::transaction()` does this for its table, code writing through
`SiteKeyStore` inside it calls `$keys->ensureSchema()` first, and settings writes (`ensureCors()`) run after the
commit. `SitePairingServiceTest` exercises this only against a real MySQL 8 (`TDS_TEST_DB_DSN`).

### Read UTC values as UTC

Pairing `expires_at` is written with `gmdate()` (UTC, no zone). `strtotime()` reads it in PHP's default zone,
which on a Berlin host made every pairing two hours old at birth (410 `pairing_expired`). Read with
`SitePairingService::utcTimestamp()`, and run the suite with `php -d date.timezone=Europe/Berlin` when touching
time.
