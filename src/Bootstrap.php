<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi;

use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Tds\CoreFrontendApi\Auth\JwksClient;
use Tds\CoreFrontendApi\Auth\TokenVerifier;
use Tds\CoreFrontendApi\Domain\DashboardLayoutRepository;
use Tds\CoreFrontendApi\Domain\UserPreferenceRepository;
use Tds\CoreFrontendApi\Support\PreferenceWhitelist;
use Tds\CoreFrontendApi\Middleware\AuthMiddleware;
use Tds\CoreFrontendApi\Middleware\CorsMiddleware;
use Tds\CoreFrontendApi\Middleware\SiteKeyMiddleware;
use Tds\CoreFrontendApi\Service\ApiReference;
use Tds\CoreFrontendApi\Service\ConnectedSiteCacheService;
use Tds\CoreFrontendApi\Service\CorsConfig;
use Tds\CoreFrontendApi\Service\HttpSiteCache;
use Tds\CoreFrontendApi\Service\MailConfig;
use Tds\CoreFrontendApi\Service\NotificationFeed;
use Tds\CoreFrontendApi\Service\NullMailer;
use Tds\CoreFrontendApi\Service\PairingRateLimiter;
use Tds\CoreFrontendApi\Service\SettingsStore;
use Tds\CoreFrontendApi\Service\SiteConnectionStore;
use Tds\CoreFrontendApi\Service\SiteKeyPolicy;
use Tds\CoreFrontendApi\Service\SiteKeyStore;
use Tds\CoreFrontendApi\Service\SitePairingException;
use Tds\CoreFrontendApi\Service\SitePairingService;
use Tds\CoreFrontendApi\Service\SmtpMailer;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\CoreFrontendApi\Support\MigrationRunner;
use Tds\Frontend\Contract\Email;
use Tds\Frontend\Contract\Mailer;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\ReportingSiteCache;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteConnectionIdentity;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SiteKeys;
use Tds\Frontend\Contract\UserContext;

/**
 * Wires the base panel API: env, Slim app, middleware, base routes, and the
 * composition of enabled extension Modules (in-process, via frontend-contract's
 * ModuleRegistry) — the backend twin of core-frontend's frontendHost.
 *
 * The base ships only the kernel routes here (/healthz, /admin/permissions);
 * user management, wiki, email etc. are ported in next. It MUST boot with zero
 * modules — extensions are additive.
 */
final class Bootstrap
{
    public static function createApp(string $rootDir): App
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        // DI container of the core services extensions may resolve (Mailer /
        // UserContext / PDO). Modules reach them via $app->getContainer().
        $container = self::container($rootDir);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV', 'production') !== 'production', true, true);
        // Compose the enabled extensions. A duplicate id / missing dep / cycle
        // throws here (fail fast at boot), and a duplicate permission/setting
        // key throws when the catalog is read below.
        //
        // Constructed BEFORE the middleware stack because the site-key gate
        // needs the merged prefix list; the routes themselves are only mounted
        // by registerAll() further down, and $app->add() applies to the whole
        // app regardless of when a route was added.
        $registry = new ModuleRegistry(Modules::enabled());

        // Site keys gate the PUBLIC SITE-READ routes each module declares
        // (SiteKeyProtected). Added FIRST of the three so it RUNS LAST of them:
        // it needs the principal AuthMiddleware populates (the admin exemption),
        // and it must sit inside CorsMiddleware so a 401 still carries the CORS
        // headers — otherwise a rejected key reports itself to the browser as a
        // CORS failure. See SiteKeyMiddleware and PreflightTest.
        $app->add(new SiteKeyMiddleware($container, $registry->siteKeyRoutes()));
        // Auth populates the request principal (UserContext) each request; it
        // does NOT gate — routes/modules enforce via the resolved context.
        $app->add(new AuthMiddleware($container, self::tokenVerifier($rootDir)));
        // Slim middleware is LIFO — the LAST added runs FIRST. CORS must be
        // added after routing so it is outermost; otherwise routing 405s an
        // OPTIONS preflight (no OPTIONS routes) before CORS can short-circuit
        // it, and browsers block every cross-origin request. See PreflightTest.
        // A predicate, not a list: the allow-list is editable in the panel
        // (Einstellungen → CORS), and a list captured here would only change on
        // the next deploy — the setting would save and quietly do nothing.
        $app->add(new CorsMiddleware(static fn (string $origin): bool => self::corsAllows($container, $origin)));

        $registry->registerAll($app);

        // In-process auto-migrate: on the first request after a deploy, apply
        // every enabled extension's pending migrations (no proc_open/cron on the
        // prod host). No-op in tests/boot (no DB) and cheap once applied (marker).
        self::autoMigrate($rootDir, $registry);

        // --- Base kernel routes -------------------------------------------------
        // Never 5xx's — the gateway's aggregate health relies on the 200 + JSON
        // contract. `db` is part of that contract (see the gateway's
        // Support\HealthBody): every backend self-reports `ok`/`no-schema`/`down`
        // so a reachable-but-un-migrated service cannot look green. auth and
        // customer always did; this service did not, so a frontend pointed at a
        // dead database reported `{"ok":true,"status":200}` and the whole API
        // looked healthy while every route 500'd.
        $app->get('/healthz', function (Request $request, Response $response) use ($container, $registry): Response {
            $payload = [
                'status' => 'ok',
                'modules' => $registry->order(),
            ];
            // Only gate when a DB was actually configured. Booting with no DB is
            // a supported state (local dev, first boot before the .env exists) —
            // reporting `down` there would flip the whole gateway to 503 for a
            // service that is behaving exactly as designed. Omitting the key
            // means "nothing to gate on" to HealthBody::dbState().
            if (self::env('DB_NAME', '') !== '') {
                $payload['db'] = self::checkDb($container);
            }
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        });

        // Merged RBAC permission catalog contributed by all modules — the base
        // surfaces it for the admin user editor (permission gating lives in each
        // module's routes/JWT, this is just the catalog).
        $app->get('/admin/permissions', function (Request $request, Response $response) use ($registry): Response {
            $permissions = array_map(
                static fn ($p): array => $p->toArray(),
                $registry->permissions(),
            );
            $response->getBody()->write(json_encode($permissions, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- Per-user dashboard layout (base service) ---------------------------
        // Any authenticated principal manages their own widget layout (which
        // widgets show + order). Keyed by the JWT user id — no admin gate.
        $app->get('/me/dashboard-layout', function (Request $request, Response $response) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }
            $layout = $container->get(DashboardLayoutRepository::class)->get((int) $user->userId());
            $response->getBody()->write(json_encode(['layout' => $layout], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->put('/me/dashboard-layout', function (Request $request, Response $response) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }
            $body = (array) $request->getParsedBody();
            $raw = $body['layout'] ?? null;
            if (!is_array($raw)) {
                $response->getBody()->write(json_encode(['error' => 'layout (array) is required'], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
            $items = [];
            $sort = 0;
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $widgetId = trim((string) ($entry['widget_id'] ?? ''));
                // Widget ids are stable kebab/colon slugs — reject anything else.
                if (preg_match('/^[a-z0-9:_-]{1,64}$/', $widgetId) !== 1) {
                    continue;
                }
                $items[] = [
                    'widget_id' => $widgetId,
                    'visible' => (bool) ($entry['visible'] ?? true),
                    'sort' => $sort++,
                ];
            }
            $container->get(DashboardLayoutRepository::class)->save((int) $user->userId(), $items);
            $response->getBody()->write(json_encode(['ok' => true, 'count' => count($items)], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- Per-user interface preferences (base service) ----------------------
        // Theme, locale and notification toggles, keyed by the JWT user id. The
        // panel already caches the theme in localStorage (the no-flash bootstrap
        // reads it before paint); this is what makes the choice follow the USER
        // to another device instead of living per browser.
        //
        // Everything here is best-effort on the client: the frontend service may
        // legitimately have no database yet (`services/frontend/.env` is still an
        // open go-live step), so a failing GET must leave the panel working off
        // localStorage rather than blocking it.
        $app->get('/me/preferences', function (Request $request, Response $response) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }
            $prefs = $container->get(UserPreferenceRepository::class)->all((int) $user->userId());
            $response->getBody()->write(json_encode(['preferences' => $prefs], JSON_THROW_ON_ERROR));
            return $response
                ->withHeader('Content-Type', 'application/json')
                // Per-user state behind a shared gateway — never let an
                // intermediary hand one user's theme to the next.
                ->withHeader('Cache-Control', 'no-store');
        });

        $app->put('/me/preferences', function (Request $request, Response $response) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }
            $body = (array) $request->getParsedBody();
            $raw = $body['preferences'] ?? null;
            if (!is_array($raw)) {
                $response->getBody()->write(json_encode(['error' => 'preferences (object) is required'], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            // A PARTIAL write of a closed whitelist — see PreferenceWhitelist
            // for why unknown keys are dropped rather than rejected.
            $accepted = PreferenceWhitelist::filter($raw);

            $container->get(UserPreferenceRepository::class)->setMany((int) $user->userId(), $accepted);
            $response->getBody()->write(json_encode(['ok' => true, 'saved' => array_keys($accepted)], JSON_THROW_ON_ERROR));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        });

        // --- Live notification feed (base service) ------------------------------
        // The ONE endpoint the panel shell polls on every page. Each composed
        // module implementing NotificationSource contributes its own events; the
        // base only merges them and carries the per-module cursors.
        //
        // Why not per extension: the shell would then need one interval per
        // module on every page. Why not SSE/WebSocket: the production host is
        // PHP-FPM behind Plesk with no long-lived workers, so polling is the
        // only option — which is also why this route must stay cheap.
        $app->get('/me/notifications', function (Request $request, Response $response) use ($container, $registry): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated()) {
                $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            $since = $request->getQueryParams()['since'] ?? null;
            $feed = new NotificationFeed($registry->notificationSources());
            $payload = $feed->collect($user, is_string($since) ? $since : null);

            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        });

        // --- Runtime settings (admin) ------------------------------------------
        // Masked read + write of a namespace's settings. Admin-only. A secret is
        // returned only as configured/last4; a blank secret on save keeps the
        // existing value (so the masked UI never has to round-trip the raw secret).
        $app->get('/admin/settings/{ns:[a-z0-9-]+}', function (Request $request, Response $response, array $args) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || !$user->isAdmin()) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return $response->withStatus($user->isAuthenticated() ? 403 : 401)->withHeader('Content-Type', 'application/json');
            }
            $settings = $container->get(SettingsStoreContract::class)->allMasked((string) $args['ns']);
            $response->getBody()->write(json_encode(['settings' => $settings], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->put('/admin/settings/{ns:[a-z0-9-]+}', function (Request $request, Response $response, array $args) use ($container): Response {
            $user = $container->get(UserContext::class);
            if (!$user->isAuthenticated() || !$user->isAdmin()) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return $response->withStatus($user->isAuthenticated() ? 403 : 401)->withHeader('Content-Type', 'application/json');
            }
            $body = (array) $request->getParsedBody();
            $items = is_array($body['settings'] ?? null) ? $body['settings'] : null;
            if ($items === null) {
                $response->getBody()->write(json_encode(['error' => 'settings (array) is required'], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
            $store = $container->get(SettingsStoreContract::class);
            $ns = (string) $args['ns'];
            $written = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = trim((string) ($item['key'] ?? ''));
                if (preg_match('/^[a-z0-9_]{1,96}$/', $key) !== 1) {
                    continue;
                }
                $secret = (bool) ($item['secret'] ?? false);
                $value = (string) ($item['value'] ?? '');
                // A blank secret means "keep existing" — skip the write.
                if ($secret && $value === '') {
                    continue;
                }
                $store->set($ns, $key, $value, $secret);
                $written++;
            }
            $response->getBody()->write(json_encode(['ok' => true, 'written' => $written], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- E-Mail / SMTP (admin) ----------------------------------------------
        // The *effective* mail configuration, which the generic settings route
        // cannot report: it only knows the `mail` namespace's stored rows, while
        // what actually sends is those rows OR the `MAIL_DSN` fallback. Without
        // this an admin sees an empty form on a host that mails perfectly well
        // and would "fix" it by overwriting a working transport.
        //
        // Carries no secret — only whether a password is stored.
        $app->get('/admin/mail', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }
            $response->getBody()->write(json_encode(self::mailConfig($container)->status(), JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Send a test mail. SMTP fails in ways a form cannot validate (wrong
        // port, refused relay, bad credentials), and the modules that use the
        // mailer send on events an admin cannot trigger at will — so "es ist
        // gespeichert" is not the same as "es verschickt", and this is the only
        // way to tell the two apart before a customer notices.
        $app->post('/admin/mail/test', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $config = self::mailConfig($container);
            if (!$config->isConfigured()) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'Kein SMTP konfiguriert.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $user = $container->get(UserContext::class);
            $body = (array) $request->getParsedBody();
            $to = trim((string) ($body['to'] ?? ''));
            if ($to === '') {
                // Default to the admin's own address — the common case, and it
                // keeps the route from being usable as a mail relay by accident.
                $to = trim((string) ($user->email() ?? ''));
            }
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'Keine gültige Empfängeradresse.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $mailer = $container->get(Mailer::class);
            if (!$mailer->isConfigured()) {
                // MailConfig said yes but the transport could not be built —
                // a malformed DSN. Report it as such instead of "gesendet".
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'Die SMTP-Verbindung konnte nicht aufgebaut werden (ungültige Konfiguration).',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
            }

            try {
                $mailer->send(new Email(
                    toEmail: $to,
                    toName: $to,
                    subject: 'Testmail aus dem Verwaltungsbereich',
                    htmlBody: '<p>Diese Testmail bestätigt, dass der E-Mail-Versand funktioniert.</p>'
                        . '<p>Absender: ' . htmlspecialchars($config->fromName, ENT_QUOTES) . ' &lt;'
                        . htmlspecialchars($config->fromEmail, ENT_QUOTES) . '&gt;<br>'
                        . 'Quelle der Konfiguration: ' . htmlspecialchars($config->source, ENT_QUOTES) . '</p>',
                    textBody: 'Diese Testmail bestätigt, dass der E-Mail-Versand funktioniert.',
                ));
            } catch (\Throwable $e) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => MailConfig::redact($e->getMessage()),
                ], JSON_THROW_ON_ERROR));
                // 502: the upstream SMTP server failed — the request was fine.
                return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['ok' => true, 'to' => $to], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- CORS / allowed origins (admin) -------------------------------------
        // Which browser origins may call this API. It lived only in
        // `CORS_ALLOWED_ORIGINS` on the host, i.e. it could be changed only by
        // editing a file over SSH on a host whose entire install model is "no
        // SSH" — so in practice it was whatever the installer wrote once.
        //
        // A dedicated pair rather than the generic settings routes, for the two
        // reasons the mail pair exists: the namespace alone cannot report what
        // is EFFECTIVE (baseline + env + stored), and a near-miss value here
        // fails silently forever — the comparison is an exact string match, so
        // a saved `https://kunde.de/` unblocks nothing and says nothing.
        $app->get('/admin/cors', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }
            $response->getBody()->write(json_encode(self::corsConfig($container)->status(), JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->put('/admin/cors', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $body = (array) $request->getParsedBody();
            $submitted = $body['origins'] ?? [];
            if (is_string($submitted)) {
                // A textarea posted as one blob — accept it, splitting on the
                // same separators the stored form uses.
                $submitted = CorsConfig::split($submitted);
            }
            if (!is_array($submitted)) {
                $response->getBody()->write(json_encode(['error' => 'origins must be a list'], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            [$accepted, $rejected] = CorsConfig::normalizeList(array_values($submitted));

            try {
                $container->get(SettingsStoreContract::class)
                    ->set(CorsConfig::NAMESPACE, CorsConfig::KEY_ORIGINS, implode("\n", $accepted), false);
            } catch (\Throwable) {
                $response->getBody()->write(json_encode([
                    'error' => 'Einstellungen konnten nicht gespeichert werden — keine Datenbank konfiguriert.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
            }

            // Report the rejects rather than swallowing them: an entry that was
            // silently dropped looks saved and blocks nothing.
            $response->getBody()->write(json_encode([
                'ok' => true,
                'saved' => $accepted,
                'rejected' => $rejected,
            ] + self::corsConfig($container)->status(), JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- Site connections / site keys ---------------------------------------
        //
        // Which public static site is connected to this API, and the credential
        // that proves it. Before this there was no such notion at all: five
        // disjoint per-site registers (the CORS origins, cms_site, blog,
        // the tools singleton, the live-chat frontend list), none of them
        // carrying a key, and the four site origins enumerated only inside a
        // frontend bundle the API could never see.
        //
        // A dedicated route set rather than the generic settings pair, for the
        // reason the mail and CORS pairs exist: the namespace alone cannot
        // answer what is EFFECTIVE. Here that means the issued keys with their
        // last-used bookkeeping, whether each site's origin is CORS-allowed at
        // all, which route prefixes the modules actually protect, and how many
        // keyless reads `warn` mode has counted.
        $app->get('/admin/sites', function (Request $request, Response $response) use ($container, $registry): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $policy = self::siteKeyPolicy($container);
            $store = self::siteKeyStore($container);
            $allowed = array_column(self::corsConfig($container)->status()['origins'], 'source', 'origin');

            $keys = $store?->all() ?? [];
            $bySite = [];
            foreach ($keys as $key) {
                $bySite[$key['site']][] = $key;
            }

            $sites = [];
            foreach ($policy->sites() as $site) {
                $origins = [];
                foreach ($site['origins'] as $origin) {
                    $origins[] = [
                        'origin' => $origin,
                        // `null` = not allowed at all. Reported per origin and
                        // not per site because the landingpage has two and a
                        // visitor who lands on `www.` posts from the other one.
                        'cors' => $allowed[$origin] ?? null,
                    ];
                }
                $sites[] = $site + [
                    'origins' => $origins,
                    'keys' => $bySite[$site['id']] ?? [],
                ];
            }

            $payload = [
                'sites' => $sites,
                'enforcement' => $policy->enforcement,
                'modes' => SiteKeyPolicy::MODES,
                'protected_routes' => $registry->siteKeyRoutes(),
                'unkeyed' => self::unkeyedState($container),
                'store_available' => $store !== null,
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        // Issue a key. The plaintext is in THIS response and nowhere else, ever
        // — only its SHA-256 digest is stored. The frontend renders it in-flow
        // to be copied, never as a toast: a value the reader must act on cannot
        // be shown in something that disappears on a timer.
        $app->post('/admin/sites', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $body = (array) $request->getParsedBody();
            $site = trim((string) ($body['site'] ?? ''));
            if ($site === '') {
                $response->getBody()->write(json_encode(['error' => 'site is required'], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $policy = self::siteKeyPolicy($container);
            $known = null;
            foreach ($policy->sites() as $candidate) {
                if ($candidate['id'] === $site) {
                    $known = $candidate;
                    break;
                }
            }
            if ($known === null) {
                // Refused rather than invented: a key for a site nobody declared
                // would match no build and no origin, and would sit in the list
                // looking configured.
                $response->getBody()->write(json_encode([
                    'error' => 'Unbekannte Site. Zuerst unter „Eigene Sites" anlegen.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $store = self::siteKeyStore($container);
            if ($store === null) {
                $response->getBody()->write(json_encode([
                    'error' => 'Site-Keys benötigen eine Datenbank — keine konfiguriert.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
            }

            try {
                $issued = $store->issue(
                    $site,
                    trim((string) ($body['label'] ?? '')) ?: (string) $known['label'],
                    (string) ($known['origins'][0] ?? ''),
                );
            } catch (\Throwable) {
                $response->getBody()->write(json_encode([
                    'error' => 'Key konnte nicht erzeugt werden — keine Datenbank konfiguriert.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'id' => $issued['id'],
                'site' => $site,
                'key' => $issued['key'],
                'key_prefix' => $issued['key_prefix'],
            ], JSON_THROW_ON_ERROR));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        });

        // Revoke. The row stays and is flagged: "this site had a key and
        // somebody revoked it on the 3rd" is exactly what the page is for, and
        // a row that vanishes answers nothing.
        $app->delete('/admin/sites/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }
            $store = self::siteKeyStore($container);
            $ok = $store !== null && $store->revoke((int) $args['id']);
            $response->getBody()->write(json_encode(
                $ok ? ['ok' => true] : ['error' => 'Nicht gefunden oder bereits widerrufen.'],
                JSON_THROW_ON_ERROR,
            ));
            return $response->withStatus($ok ? 200 : 404)->withHeader('Content-Type', 'application/json');
        });

        // Enforcement mode + the custom site list.
        $app->put('/admin/sites', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $body = (array) $request->getParsedBody();
            $rejected = [];
            $writes = [];

            if (array_key_exists('enforcement', $body)) {
                $mode = SiteKeyPolicy::normalizeMode((string) $body['enforcement']);
                if ($mode === null) {
                    $response->getBody()->write(json_encode([
                        'error' => 'enforcement must be one of: ' . implode(', ', SiteKeyPolicy::MODES),
                    ], JSON_THROW_ON_ERROR));
                    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                }
                $writes[SiteKeyPolicy::KEY_ENFORCEMENT] = $mode;
            }

            if (array_key_exists('sites', $body)) {
                if (!is_array($body['sites'])) {
                    $response->getBody()->write(json_encode(['error' => 'sites must be a list'], JSON_THROW_ON_ERROR));
                    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                }
                [$accepted, $rejected] = SiteKeyPolicy::normalizeSites(array_values($body['sites']));
                $writes[SiteKeyPolicy::KEY_CUSTOM_SITES] = SiteKeyPolicy::encodeSites($accepted);
            }

            // Explicit reset of the warn counter — otherwise the number only
            // ever grows and stops meaning "still keyless" the moment one site
            // is fixed.
            if (($body['reset_unkeyed'] ?? false) === true) {
                $writes[SiteKeyPolicy::KEY_UNKEYED] = '';
            }

            try {
                $store = $container->get(SettingsStoreContract::class);
                foreach ($writes as $key => $value) {
                    $store->set(SiteKeyPolicy::NAMESPACE, $key, $value, false);
                }
            } catch (\Throwable) {
                $response->getBody()->write(json_encode([
                    'error' => 'Einstellungen konnten nicht gespeichert werden — keine Datenbank konfiguriert.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
            }

            $policy = self::siteKeyPolicy($container);
            $response->getBody()->write(json_encode([
                'ok' => true,
                'enforcement' => $policy->enforcement,
                'sites' => $policy->sites(),
                // Reported, never swallowed: an entry that was silently dropped
                // looks saved, and the key issued for it matches nothing.
                'rejected' => $rejected,
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // The handshake the /install wizard performs on each public site.
        //
        // PUBLIC by necessity — it runs in the operator's browser on the site's
        // own domain, before anything is connected, exactly like the tools
        // registry sync it sits next to. The key goes in the BODY, never a
        // header (no new preflight) and never the query string (a credential in
        // an access log outlives its use).
        //
        // It is also the only thing that tells the panel a site exists at all:
        // `tds-runtime.json` is written by hand on the host, so without this
        // there is no moment at which the API learns which apiBase a site
        // published.
        $app->post('/sites/handshake', function (Request $request, Response $response) use ($container): Response {
            $body = (array) $request->getParsedBody();
            $key = trim((string) ($body['key'] ?? ''));
            $site = trim((string) ($body['site'] ?? ''));
            $apiBase = trim((string) ($body['apiBase'] ?? ''));
            $origin = $request->getHeaderLine('Origin');

            $store = self::siteKeyStore($container);
            $identity = $store?->verify($key, $site !== '' ? $site : null, $origin !== '' ? $origin : null);
            if ($identity === null) {
                $response->getBody()->write(json_encode([
                    'error' => 'Site-Key abgelehnt.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            $store?->touch($identity->id, $origin !== '' ? $origin : null, $apiBase !== '' ? $apiBase : null);

            // Report the CORS state of the origin this very request came from,
            // not of the origin recorded on the key: the operator may be running
            // the wizard on a staging host, and telling them about the
            // production origin would be confidently wrong.
            $corsOk = $origin === '' || self::corsAllows($container, $origin);

            $response->getBody()->write(json_encode([
                'ok' => true,
                'site' => $identity->site,
                'label' => $identity->label,
                'cors' => $corsOk ? 'allowed' : 'missing',
                'origin' => $origin,
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        // A public site's server exchanges the ten-minute token received at
        // POST /tds/connect. The token is never accepted in a query string and
        // only its SHA-256 digest exists in the database.
        $app->post('/sites/pairings/exchange', function (Request $request, Response $response) use ($container): Response {
            $pairings = self::sitePairingService($container);
            if ($pairings === null) {
                return self::pairingError($response, new SitePairingException(
                    'Site-Verbindungen benötigen eine konfigurierte Datenbank.',
                    503,
                    'pairing_unavailable',
                ));
            }
            $body = (array) $request->getParsedBody();
            try {
                $payload = $pairings->exchange(
                    (string) ($body['pairing_token'] ?? ''),
                    (string) ($body['profile'] ?? ''),
                    (string) ($body['origin'] ?? ''),
                    self::requestOrigin($request),
                );
            } catch (SitePairingException $e) {
                return self::pairingError($response, $e);
            } catch (\Throwable) {
                return self::pairingError($response, new SitePairingException(
                    'Pairing konnte nicht ausgetauscht werden.',
                    503,
                    'pairing_unavailable',
                ));
            }
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        // Phase two activates the pending key only after the site has written
        // its private connection file. Repeating this call is safe and returns
        // the already-active connection.
        $app->post('/sites/pairings/finalize', function (Request $request, Response $response) use ($container): Response {
            $pairings = self::sitePairingService($container);
            if ($pairings === null) {
                return self::pairingError($response, new SitePairingException(
                    'Site-Verbindungen benötigen eine konfigurierte Datenbank.',
                    503,
                    'pairing_unavailable',
                ));
            }
            $body = (array) $request->getParsedBody();
            try {
                $connection = $pairings->finalize(
                    (string) ($body['pairing_id'] ?? ''),
                    (string) ($body['finalize_token'] ?? ''),
                    (string) ($body['profile'] ?? ''),
                    (string) ($body['origin'] ?? ''),
                );
            } catch (SitePairingException $e) {
                return self::pairingError($response, $e);
            } catch (\Throwable) {
                return self::pairingError($response, new SitePairingException(
                    'Pairing konnte nicht finalisiert werden.',
                    503,
                    'pairing_unavailable',
                ));
            }
            $response->getBody()->write(json_encode([
                'ok' => true,
                'connection' => $connection->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        // Local, read-only module inventory. Releases and dependency changes
        // happen in GitHub CI; the running API neither queries nor dispatches it.
        $app->get('/admin/modules', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }
            $response->getBody()->write(json_encode(self::backendPackageVersions(), JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        // The admin frontend's API reference: the base plus every composed
        // module, introspected from the registered Slim routes at request time
        // (so all modules are present) and joined with the prose each module
        // contributes through ApiDocSource. Admin-only — the customer portal's
        // Wiki is a different page entirely (FAQ + Handbücher, no API).
        //
        // Building it lives in Service\ApiReference; this handler is the gate.
        $app->get('/wiki.json', function (Request $request, Response $response) use ($app, $registry): Response {
            $user = $app->getContainer()?->get(UserContext::class);
            if ($user === null || !$user->isAdmin()) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return $response->withStatus($user === null || !$user->isAuthenticated() ? 401 : 403)
                    ->withHeader('Content-Type', 'application/json');
            }
            $payload = (new ApiReference($app, $registry))->build();
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        return $app;
    }

    /**
     * Admin gate for a route: returns a ready 401/403 response when the
     * principal may not proceed, or null when it may.
     *
     * The base's older routes inline this same five-line block; new ones use
     * the helper. Returning the response (rather than throwing) keeps the call
     * sites' early-return shape.
     */
    private static function denyUnlessAdmin(Container $container, Response $response): ?Response
    {
        $user = $container->get(UserContext::class);
        if ($user->isAuthenticated() && $user->isAdmin()) {
            return null;
        }
        $response->getBody()->write(json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
        return $response->withStatus($user->isAuthenticated() ? 403 : 401)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * SMTP configuration, read DB-first with `MAIL_DSN` as the env fallback.
     * Defensive so the settings page still renders without a database.
     */
    private static function mailConfig(Container $container): MailConfig
    {
        $store = null;
        try {
            $store = $container->get(SettingsStoreContract::class);
        } catch (\Throwable) {
            // No DB / no container binding — env-only.
        }
        return MailConfig::resolve($store, static fn (string $k, ?string $d): string => self::env($k, $d));
    }

    /**
     * Installed versions of the first-party Composer packages making up THIS
     * API bundle — the backend half of every module.
     *
     * It matters because a module has two halves on two pipelines: the npm
     * package a product build composes, and the Composer package the gateway
     * bundle assembles. Showing only the frontend version would let an admin
     * conclude a module is up to date while its PHP side is a release behind.
     *
     * @return array{modules: string[], packages: array<string,string>}
     */
    private static function backendPackageVersions(): array
    {
        $packages = [];
        try {
            foreach (\Composer\InstalledVersions::getInstalledPackages() as $name) {
                if (!str_starts_with($name, 'tracht-digital-solutions/')) {
                    continue;
                }
                $version = \Composer\InstalledVersions::getPrettyVersion($name);
                if (is_string($version) && $version !== '') {
                    $packages[$name] = $version;
                }
            }
        } catch (\Throwable) {
            // Composer 1 / no runtime API — the frontend half still renders.
        }
        ksort($packages);

        return [
            'modules' => (new ModuleRegistry(Modules::enabled()))->order(),
            'packages' => $packages,
        ];
    }

    /**
     * The DI container of core services exposed to modules. All bindings are
     * lazy so boot stays side-effect-free (no DB connect, no SMTP handshake).
     */
    private static function container(string $rootDir): Container
    {
        $container = new Container();

        // Shared DB connection (extensions store their own tables through it).
        $container->set(PDO::class, static function (): PDO {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::env('DB_HOST', '127.0.0.1'),
                self::env('DB_PORT', '3306'),
                self::env('DB_NAME', ''),
            );
            return new PDO($dsn, self::env('DB_USER', ''), self::env('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        });

        // Core SMTP mailer. Configured DB-first (Einstellungen → E-Mail (SMTP))
        // with `MAIL_DSN` as the env fallback; unconfigured → a no-op mailer, so
        // a module can call send() unconditionally. Config + From live here only.
        //
        // Lazy like every other binding: resolving MailConfig touches the
        // settings store, which touches PDO — doing that at boot would take the
        // service down on a host whose DB config is not in place yet.
        $container->set(Mailer::class, static function ($c): Mailer {
            $config = self::mailConfig($c);
            if (!$config->isConfigured()) {
                return new NullMailer();
            }
            try {
                $transport = Transport::fromDsn($config->dsn);
            } catch (\Throwable) {
                // A malformed stored DSN must not 500 every route that merely
                // *resolves* the mailer — degrade to the no-op one instead. The
                // settings page reports the failure through the test route.
                return new NullMailer();
            }
            return new SmtpMailer(
                new SymfonyMailer($transport),
                $config->fromEmail,
                $config->fromName,
            );
        });

        // The public sites' page cache. Bound here, like Mailer, so no
        // extension holds an HTTP client, a token or a URL policy of its own.
        // ConnectedSiteCacheService selects the paired resource and decrypts
        // its cache token without exposing either value to the browser.
        //
        // It never throws: a site that is down must not turn "save this
        // article" into an error. Stateless and cheap to construct, so it does
        // not need the lazy treatment the DB-touching bindings do.
        $container->set(ReportingSiteCache::class, static fn (): ReportingSiteCache => new HttpSiteCache());
        // Compatibility adapter for extensions released against 1.10.x.
        $container->set(SiteCache::class, static fn ($c): SiteCache => $c->get(ReportingSiteCache::class));

        // The default binding is anonymous; AuthMiddleware rebinds it per
        // request to a JwtUserContext when a valid token is presented.
        $container->set(UserContext::class, static fn (): UserContext => new AnonymousUserContext());
        $container->set(SiteConnectionIdentity::class, static fn (): SiteConnectionIdentity => new SiteConnectionIdentity());

        // Base-service per-user dashboard layout store (lazy — no DB on boot).
        $container->set(DashboardLayoutRepository::class, static fn ($c): DashboardLayoutRepository =>
            new DashboardLayoutRepository($c->get(PDO::class)));

        // Base-service per-user interface preferences (theme/locale/notifications).
        // Lazy for the same reason: PDO must not be constructed at boot, or the
        // service cannot start on a host whose DB config is not in place yet.
        $container->set(UserPreferenceRepository::class, static fn ($c): UserPreferenceRepository =>
            new UserPreferenceRepository($c->get(PDO::class)));

        // Runtime settings store (namespaced key/value; secrets AES-256-GCM at
        // rest under SETTINGS_ENCRYPTION_KEY). Bound by the CONTRACT interface key
        // so modules resolve it the same way they resolve Mailer/UserContext, and
        // read their own namespace DB-first with an env fallback.
        $container->set(SettingsStoreContract::class, static fn ($c): SettingsStore =>
            new SettingsStore($c->get(PDO::class), self::env('SETTINGS_ENCRYPTION_KEY', '')));

        // Site keys — the credential a public static site presents. Bound by the
        // CONTRACT key so a module (tools' registry sync) resolves it exactly
        // like Mailer/SettingsStore, and null-safely: this factory constructs
        // PDO, so resolving it is a database connection. Callers that can avoid
        // asking must avoid asking (see SiteKeyMiddleware).
        //
        // Note this store needs NO SETTINGS_ENCRYPTION_KEY: only a SHA-256 hash
        // is persisted, which is what lets site keys work on a host where that
        // variable was never set.
        $container->set(SiteKeys::class, static fn ($c): SiteKeyStore => new SiteKeyStore(
            $c->get(PDO::class),
            self::siteKeyPolicy($c),
        ));

        $container->set(SiteConnectionStore::class, static fn ($c): SiteConnectionStore => new SiteConnectionStore(
            $c->get(PDO::class),
            self::env('SETTINGS_ENCRYPTION_KEY', ''),
        ));
        $container->set(PairingRateLimiter::class, static fn (): PairingRateLimiter => new PairingRateLimiter(
            $rootDir . '/var/pairing-rate-limit',
        ));
        $container->set(SiteConnections::class, static fn ($c): SitePairingService => new SitePairingService(
            $c->get(SiteConnectionStore::class),
            $c->get(SiteKeys::class),
            self::env('SETTINGS_ENCRYPTION_KEY', ''),
            $c->get(PairingRateLimiter::class),
            self::env('APP_ENV', 'production') !== 'production',
            $c->get(SettingsStoreContract::class),
        ));
        $container->set(ConnectedSiteCache::class, static fn ($c): ConnectedSiteCache => new ConnectedSiteCacheService(
            $c->get(SiteConnectionStore::class),
            $c->get(ReportingSiteCache::class),
        ));

        return $container;
    }

    /**
     * The JWKS token verifier, or null when auth is unconfigured (`AUTH_API_URL`
     * unset — local dev / boot) so every request is anonymous rather than 500ing.
     */
    private static function tokenVerifier(string $rootDir): ?TokenVerifier
    {
        $authUrl = self::env('AUTH_API_URL', '');
        if ($authUrl === '') {
            return null;
        }
        return new JwksClient(
            new GuzzleClient(['timeout' => 5]),
            rtrim($authUrl, '/') . '/.well-known/jwks.json',
            $rootDir . '/var/cache',
            (int) self::env('JWKS_CACHE_TTL', '600'),
        );
    }

    /**
     * All enabled modules' Phinx migration directories, for the in-process
     * auto-migrator. Exposed so a caller can consume it without rebuilding the
     * registry.
     *
     * @return string[]
     */
    public static function migrationPaths(): array
    {
        return array_merge(
            [dirname(__DIR__) . '/migrations'],
            (new ModuleRegistry(Modules::enabled()))->migrationPaths(),
        );
    }

    /**
     * Run the in-process auto-migrator. Gated so it is a true no-op when no DB is
     * configured (unit tests / cold boot) or when explicitly disabled
     * (`AUTO_MIGRATE=0`); otherwise it applies pending migrations once per
     * deployed migration-set (see {@see MigrationRunner}).
     */
    /**
     * Two-stage DB probe for /healthz, mirroring tds-auth-api's HealthAction.
     *
     * A bare `SELECT 1` succeeds against an empty, never-migrated database, so
     * it would report `ok` while every module route 500s on its missing tables.
     * Probing `phinxlog` — the shared migration log the in-process
     * MigrationRunner writes, and the same table the installer verifies against
     * — distinguishes "reachable but never migrated" from "reachable + ready".
     *
     * Resolves PDO inside the try/catch: the container binds it lazily, so a
     * bad DSN/credentials throws here rather than at boot, and this must report
     * `down` with HTTP 200 instead of 5xx'ing (the never-5xx contract).
     *
     * @return 'ok'|'no-schema'|'down'
     */
    private static function checkDb(Container $container): string
    {
        try {
            $pdo = $container->get(PDO::class);
            $pdo->query('SELECT 1');
        } catch (\Throwable) {
            return 'down';
        }

        try {
            $pdo->query('SELECT 1 FROM `phinxlog` LIMIT 1');
            return 'ok';
        } catch (\Throwable $e) {
            // SQLSTATE 42S02 = base table not found, i.e. reachable but the
            // migrations never ran.
            $missingTable = ($e instanceof \PDOException && $e->getCode() === '42S02')
                || str_contains($e->getMessage(), '42S02');
            return $missingTable ? 'no-schema' : 'down';
        }
    }

    private static function autoMigrate(string $rootDir, ModuleRegistry $registry): void
    {
        if (self::env('DB_NAME', '') === '' || self::env('AUTO_MIGRATE', '1') === '0') {
            return;
        }
        (new MigrationRunner(
            array_merge([dirname(__DIR__) . '/migrations'], $registry->migrationPaths()),
            [
                'host' => self::env('DB_HOST', '127.0.0.1'),
                'port' => self::env('DB_PORT', '3306'),
                'name' => self::env('DB_NAME', ''),
                'user' => self::env('DB_USER', ''),
                'pass' => self::env('DB_PASS', ''),
            ],
            $rootDir . '/var/migrate',
        ))->ensureMigrated();
    }

    /**
     * Env reader. NB explicit `?? false` checks — never
     * `$_ENV[$key] ?? getenv($key) ?: $default`, which clobbers falsy values
     * ("0", "") because `??` binds tighter than `?:` (the trap that bit all
     * four APIs via copy-paste).
     */
    private static function env(string $key, ?string $default = null): string
    {
        $v = $_ENV[$key] ?? false;
        if ($v === false) {
            $v = getenv($key);
        }
        if ($v === false) {
            $v = $default;
        }
        if ($v === null) {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return (string) $v;
    }

    /**
     * The effective CORS allow-list: the coded first-party baseline, plus
     * `CORS_ALLOWED_ORIGINS` from the host `.env`, plus whatever an admin has
     * added in the panel — a UNION, never an override. See {@see CorsConfig}
     * for why this one namespace does not let the database win.
     *
     * Defensive for the same reason as {@see mailConfig}: it runs on every
     * request through the middleware, so a host with no database yet must get
     * baseline + env rather than an exception.
     */
    /**
     * May this origin call the API? The middleware's hot path.
     *
     * Checks the two free layers first — the coded baseline and
     * `CORS_ALLOWED_ORIGINS` — and only asks the settings store about an origin
     * neither covers. That ordering is the whole point: this runs outermost on
     * EVERY request, preflights included, so resolving the store unconditionally
     * would put a PDO connect in front of the entire API. With a database that
     * is down or firewalled that is not a slow request but a hung one, and it
     * would hang the health check too. Requests from the first-party frontends,
     * which is nearly all of them, never touch the database here.
     */
    private static function corsAllows(Container $container, string $origin): bool
    {
        if ($origin === '') {
            return false;
        }
        $env = static fn (string $k, ?string $d = null): string => self::env($k, $d);
        if (in_array($origin, CorsConfig::staticOrigins($env), true)) {
            return true;
        }
        return in_array($origin, self::corsConfig($container)->custom, true);
    }

    private static function corsConfig(Container $container): CorsConfig
    {
        $store = null;
        // Gate on a configured database before even resolving the store: the
        // binding constructs a PDO eagerly, and on a host without one that
        // would be a failed connection attempt on EVERY request (this
        // middleware is outermost), not just on the routes that need data.
        if (self::env('DB_NAME', '') !== '') {
            try {
                $store = $container->get(SettingsStoreContract::class);
            } catch (\Throwable) {
                // No DB / no container binding — baseline + env only.
            }
        }
        return CorsConfig::resolve($store, static fn (string $k, ?string $d): string => self::env($k, $d));
    }

    /**
     * The site-key policy, resolved the same guarded way as {@see corsConfig()}:
     * no DB configured means no stored layer, and the env value (or `off`) is
     * the whole answer. Same reason too — the settings binding constructs a PDO,
     * so asking for it on a host without one is a failed connection attempt, not
     * an empty result.
     */
    private static function siteKeyPolicy(Container $container): SiteKeyPolicy
    {
        $store = null;
        if (self::env('DB_NAME', '') !== '') {
            try {
                $store = $container->get(SettingsStoreContract::class);
            } catch (\Throwable) {
                // No DB / no binding — env only.
            }
        }
        return SiteKeyPolicy::resolve($store, static fn (string $k, ?string $d): string => self::env($k, $d));
    }

    /**
     * The site-key store, or null when there is no database to hold one.
     *
     * Guarded on `DB_NAME` before resolving, for the reason spelled out on
     * {@see corsConfig()}: the binding constructs a PDO, so on an unconfigured
     * host asking for it is a failed connection, not an empty answer.
     */
    private static function siteKeyStore(Container $container): ?SiteKeyStore
    {
        if (self::env('DB_NAME', '') === '') {
            return null;
        }
        try {
            $store = $container->get(SiteKeys::class);
            return $store instanceof SiteKeyStore ? $store : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sitePairingService(Container $container): ?SitePairingService
    {
        if (self::env('DB_NAME', '') === '') {
            return null;
        }
        try {
            $service = $container->get(SiteConnections::class);
            return $service instanceof SitePairingService ? $service : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function requestOrigin(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if ($host === '') {
            $host = strtolower(trim(explode(':', $request->getHeaderLine('Host'), 2)[0] ?? ''));
        }
        if ($scheme === '') {
            $scheme = 'https';
        }
        $port = $uri->getPort();
        return SitePairingService::origin(
            $scheme . '://' . $host . ($port !== null ? ':' . $port : ''),
            true,
            self::env('APP_ENV', 'production') !== 'production',
        );
    }

    private static function pairingError(Response $response, SitePairingException $error): Response
    {
        $response->getBody()->write(json_encode([
            'error' => $error->errorCode,
            'message' => $error->getMessage(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($error->httpStatus)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * What `warn` mode has counted so far, or nulls when nothing has been
     * counted. Shown in the panel so an operator can tell "no keyless reads"
     * from "nobody has built since I switched this on".
     *
     * @return array{count: int, first_at: ?string, last_at: ?string, last_path: ?string, last_origin: ?string}
     */
    private static function unkeyedState(Container $container): array
    {
        $empty = ['count' => 0, 'first_at' => null, 'last_at' => null, 'last_path' => null, 'last_origin' => null];
        if (self::env('DB_NAME', '') === '') {
            return $empty;
        }
        try {
            $raw = $container->get(SettingsStoreContract::class)
                ->get(SiteKeyPolicy::NAMESPACE, SiteKeyPolicy::KEY_UNKEYED, '') ?? '';
            $state = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $empty;
        }
        if (!is_array($state)) {
            return $empty;
        }
        return [
            'count' => (int) ($state['count'] ?? 0),
            'first_at' => isset($state['first_at']) ? (string) $state['first_at'] : null,
            'last_at' => isset($state['last_at']) ? (string) $state['last_at'] : null,
            'last_path' => isset($state['last_path']) ? (string) $state['last_path'] : null,
            'last_origin' => isset($state['last_origin']) ? (string) $state['last_origin'] : null,
        ];
    }
}
