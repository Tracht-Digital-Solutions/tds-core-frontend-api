# Settings store and core services

`Bootstrap::container()` binds the services modules resolve via `$app->getContainer()->get(...)`, all
lazily (boot does no DB or SMTP work).

## `Service\SettingsStore`

Bound as the contract's `SettingsStore`: a namespaced key/value store so third-party config is editable
in the panel instead of `.env`-only.

- **Read pattern: DB first, env fallback, coded default.** (CORS is the one exception; see below.)
- **Secrets are AES-256-GCM-encrypted** under `SETTINGS_ENCRYPTION_KEY` (`v1:base64(iv|tag|cipher)`).
- `GET` / `PUT /admin/settings/{ns}` (admin only) return masked state (`configured` + `last4`); a **blank
  secret on save keeps the existing value**.
- `app_setting` (`namespace` × `skey`, `svalue`, `is_secret`) **self-bootstraps** (see
  [migrations.md](migrations.md)).
- Namespaces are per extension (`blog-cms`, `website-cms`, `tools`, `lexware`, …). The base uses `mail`,
  `stripe`, `sites` and `cors`.
- Page-cache tokens: `blog-cms/cache_token` (`BLOG_CACHE_TOKEN`) and `website-cms/cache_token`
  (`WEBSITE_CACHE_TOKEN`). They are documented in this service's `.env.example` and marked runtime-configured
  in the gateway's parity list.

## `Mailer` (`Service\MailConfig`, namespace `mail`)

SMTP via Symfony Mailer. **The stored configuration beats `MAIL_DSN`**: an install-time `.env` must not
shadow the panel form. Nothing configured → `NullMailer` (`isConfigured()` false). The From identity is
core-owned (`MAIL_FROM` / `MAIL_FROM_NAME`, overridable in the store); no extension configures SMTP.

- The form (*Einstellungen → E-Mail (SMTP)*, admin product) writes `host`, `port`, `security`, `user`,
  `password`, `from_email`, `from_name`, plus a raw `dsn` escape hatch, via `PUT /admin/settings/mail`.
- **`GET /admin/mail`** reports the effective config (`source: db|env|none`).
- **`POST /admin/mail/test`** sends a test mail. SMTP errors go through `MailConfig::redact()`, because
  Symfony echoes the DSN including the password in some failures.
- A malformed stored DSN degrades to `NullMailer`; resolving the mailer must never 500 another route.
- **Keep `symfony/mailer` and `symfony/mime` on the same major as `tds-customer-api`.** In the gateway's
  in-process mode the first-dispatched service's autoloader wins for shared packages (customer is dispatched
  before frontend by the aggregate `/healthz`). `symfony/mime` is declared explicitly.

## Stripe (`Service\StripeConfig`, namespace `stripe`)

The **one** Stripe account every charging module uses, bound as the contract's `StripeApi`. The key is
saved through the generic settings route (secret); `GET /admin/stripe` reports what is effective (stored key
or `STRIPE_SECRET_KEY`) and lists the webhook endpoints composed modules expect (`StripeWebhookSource`);
`POST /admin/stripe/test` proves the key works.

## CORS (`Service\CorsConfig`)

The allow-list is **three unioned layers**: coded baseline ∪ `CORS_ALLOWED_ORIGINS` ∪ rows edited under
*Einstellungen → CORS / Freigegebene Origins*.

- **Here the database does not outrank the env.** Removing your own frontend's origin in the panel would
  lock the panel out with no way back; union plus baseline means no browser edit can cost you the browser.
- The baseline includes `www.tracht-digital.de` as well as the apex (contact forms posted from `www.` would
  otherwise fail silently).
- **The middleware takes a predicate and checks the free layers first.** `corsAllows()` answers from
  baseline + env without touching the DB and only consults stored rows for an origin neither covers.
  Resolving the store unconditionally would put a PDO connect (or a hang) in front of every request,
  including `/healthz`.
- **`PUT /admin/cors` normalises** trailing slashes and host case and **refuses with a reason** a path, a
  non-http(s) scheme, credentials or `*` (the list is served with
  `Access-Control-Allow-Credentials: true`, where the wildcard is forbidden).
- **`GET /admin/cors`** reports the effective list with each entry's layer.
- `Access-Control-Allow-Methods` must list every method a frontend uses (a missing `PATCH` once made the
  contact triage button silently dead). `PreflightTest` asserts the whole list.

## `SiteCache` (`Service\HttpSiteCache`) and `ConnectedSiteCache`

Asks a public site to re-render cached pages affected by a content change. Bound here so no extension
carries its own HTTP client, token or URL policy. Paired connections use
`Service\ConnectedSiteCacheService`.

- **It never throws and never fails a save.** Failures go to `error_log`; the panel has a rebuild button.
- `Content-Type: application/json` is mandatory: the receiving Astro route's `security.checkOrigin` rejects
  form-ish cross-site POSTs as CSRF.
- Timeouts are short (it runs inside the save request).
- **The cache URL is an exact http(s) origin and redirects are forbidden.** Userinfo, path, query or
  fragment make it unconfigured (a trailing slash is tolerated). The `X-TDS-Cache-Token` header would be
  re-sent on a followed redirect, even to another host, so `CURLOPT_FOLLOWLOCATION` stays `false`.
  `HttpSiteCacheTest` pins both.

## Env contract

- **Quote any `.env` value containing a space** (`MAIL_FROM_NAME` above all). phpdotenv rejects it in
  `createApp()` and the whole service dies at boot (`"/frontend": {"status": 0}`, 500 everywhere, nothing
  logged).
- **A composed extension's env vars belong in this `.env.example`**; the service is the deployment unit.
- `tds-gateway-api/scripts/check-env-parity.php` fails the assemble when `.env.example` and the gateway's
  `install.php` disagree. A new env var needs either an installer prompt or a `DEFAULTED` entry with a reason.
