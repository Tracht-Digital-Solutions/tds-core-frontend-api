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
use Tds\CoreFrontendApi\Service\ApiReference;
use Tds\CoreFrontendApi\Service\AutoUpdater;
use Tds\CoreFrontendApi\Service\MailConfig;
use Tds\CoreFrontendApi\Service\ModuleUpdateConfig;
use Tds\CoreFrontendApi\Service\NotificationFeed;
use Tds\CoreFrontendApi\Service\NullMailer;
use Tds\CoreFrontendApi\Service\PackageRegistry;
use Tds\CoreFrontendApi\Service\SettingsStore;
use Tds\CoreFrontendApi\Service\SmtpMailer;
use Tds\CoreFrontendApi\Service\WorkflowDispatcher;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\CoreFrontendApi\Support\MigrationRunner;
use Tds\Frontend\Contract\Email;
use Tds\Frontend\Contract\Mailer;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;
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
        $container = self::container();
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV', 'production') !== 'production', true, true);
        // Auth populates the request principal (UserContext) each request; it
        // does NOT gate — routes/modules enforce via the resolved context.
        $app->add(new AuthMiddleware($container, self::tokenVerifier($rootDir)));
        // Slim middleware is LIFO — the LAST added runs FIRST. CORS must be
        // added after routing so it is outermost; otherwise routing 405s an
        // OPTIONS preflight (no OPTIONS routes) before CORS can short-circuit
        // it, and browsers block every cross-origin request. See PreflightTest.
        $app->add(new CorsMiddleware(self::corsOrigins()));

        // Compose the enabled extensions. A duplicate id / missing dep / cycle
        // throws here (fail fast at boot), and a duplicate permission/setting
        // key throws when the catalog is read below.
        $registry = new ModuleRegistry(Modules::enabled());
        $registry->registerAll($app);

        // In-process auto-migrate: on the first request after a deploy, apply
        // every enabled extension's pending migrations (no proc_open/cron on the
        // prod host). No-op in tests/boot (no DB) and cheap once applied (marker).
        self::autoMigrate($rootDir, $registry);

        // Unattended module updates. There is no cron on the prod host, so this
        // piggybacks on request traffic exactly like the auto-migrator does: a
        // single file read per request, and real work only once per configured
        // interval. Gated on a DB being configured so tests and cold boot stay
        // side-effect-free, and it never throws (see AutoUpdater::maybeRun).
        if (self::env('DB_NAME', '') !== '') {
            self::autoUpdater($container, $rootDir)->maybeRun();
        }

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

        // --- Module inventory + update (admin) ----------------------------------
        // The panel's Module page. The BUILD knows what is installed (each
        // manifest's version, baked into the static product); this side supplies
        // the two things a browser cannot: what the registry currently publishes
        // (the token must never reach the client) and the ability to start the
        // pipeline that puts a newer version into service.
        //
        // POST, not GET, because the package list is the input — the composed set
        // is a property of the *frontend* build, which the API does not know.
        $app->post('/admin/modules/check', function (Request $request, Response $response) use ($container, $rootDir): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $config = self::moduleUpdateConfig($container);
            $body = (array) $request->getParsedBody();
            $inventory = is_array($body['inventory'] ?? null) ? $body['inventory'] : [];
            $requested = is_array($body['packages'] ?? null)
                ? $body['packages']
                : array_map(static fn ($e): mixed => is_array($e) ? ($e['pkg'] ?? '') : '', $inventory);
            $packages = array_values(array_filter(
                array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $requested),
                static fn (string $p): bool => $p !== '' && PackageRegistry::isAllowed($p),
            ));

            // Remember the build-time inventory: the pinned ranges live in the
            // product's package.json, which this API never sees, and the
            // unattended updater needs them.
            $updater = self::autoUpdater($container, $rootDir, $config);
            if ($inventory !== []) {
                $updater->rememberInventory($inventory);
            }

            $registry = new PackageRegistry($config->registryToken);
            $versions = $registry->isConfigured() ? $registry->latestMany($packages) : [];

            $response->getBody()->write(json_encode([
                'auto' => $updater->state(),
                'versions' => (object) $versions,
                'registry' => [
                    'configured' => $registry->isConfigured(),
                    // Surfaced verbatim: "Token abgelehnt" and "Paket unbekannt"
                    // need completely different fixes, and the admin is the one
                    // who has to make them.
                    'error' => $registry->lastError(),
                ],
                'targets' => $config->targets(),
                'backend' => self::backendPackageVersions(),
                'checked_at' => date('c'),
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Run the unattended check NOW, regardless of schedule — the panel's
        // "Jetzt prüfen und aktualisieren". `force` runs it even while the
        // automation is switched off, so an admin can try it before enabling it.
        $app->post('/admin/modules/auto-update', function (Request $request, Response $response) use ($container, $rootDir): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }
            $updater = self::autoUpdater($container, $rootDir);
            $report = $updater->run(true);
            $response->getBody()->write(json_encode([
                'report' => $report,
                'auto' => $updater->state(),
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Start one deploy pipeline. This is what "Modul aktualisieren" does:
        // there is no runtime module swap — composition is a build step.
        $app->post('/admin/modules/deploy', function (Request $request, Response $response) use ($container): Response {
            $denied = self::denyUnlessAdmin($container, $response);
            if ($denied !== null) {
                return $denied;
            }

            $body = (array) $request->getParsedBody();
            $key = trim((string) ($body['target'] ?? ''));
            $config = self::moduleUpdateConfig($container);
            $target = $config->target($key);
            if ($target === null) {
                $response->getBody()->write(json_encode([
                    'error' => 'Unbekanntes oder nicht konfiguriertes Ziel.',
                    'target' => $key,
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $result = (new WorkflowDispatcher($config->dispatchToken))
                ->dispatch($target['repo'], $target['workflow'], $config->ref);

            $response->getBody()->write(json_encode([
                'ok' => $result['ok'],
                'target' => $key,
                'repo' => $target['repo'],
                'workflow' => $target['workflow'],
                'message' => $result['message'],
            ], JSON_THROW_ON_ERROR));
            // 502: the dispatch itself failed upstream — the request was fine.
            return $response->withStatus($result['ok'] ? 202 : 502)
                ->withHeader('Content-Type', 'application/json');
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
     * Module-page configuration, read DB-first with an env fallback. The store
     * is resolved lazily and defensively: without a database the whole page
     * still renders, reporting "nicht konfiguriert".
     */
    private static function moduleUpdateConfig(Container $container): ModuleUpdateConfig
    {
        $store = null;
        try {
            $store = $container->get(SettingsStoreContract::class);
        } catch (\Throwable) {
            // No DB / no container binding — env-only.
        }
        return ModuleUpdateConfig::resolve($store, static fn (string $k, ?string $d): string => self::env($k, $d));
    }

    /**
     * SMTP configuration, read DB-first with `MAIL_DSN` as the env fallback.
     * Defensive for the same reason as {@see moduleUpdateConfig}: the settings
     * page must render on a host that has no database yet.
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
     * The unattended module updater, sharing the settings store and the same
     * `var/` marker convention the auto-migrator uses.
     */
    private static function autoUpdater(Container $container, string $rootDir, ?ModuleUpdateConfig $config = null): AutoUpdater
    {
        $store = null;
        try {
            $store = $container->get(SettingsStoreContract::class);
        } catch (\Throwable) {
            /* no DB — the updater degrades to "nothing stored" */
        }
        return new AutoUpdater(
            $config ?? self::moduleUpdateConfig($container),
            $store,
            $rootDir . '/var/auto-update',
        );
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
    private static function container(): Container
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

        // The default binding is anonymous; AuthMiddleware rebinds it per
        // request to a JwtUserContext when a valid token is presented.
        $container->set(UserContext::class, static fn (): UserContext => new AnonymousUserContext());

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
        return (new ModuleRegistry(Modules::enabled()))->migrationPaths();
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
            $registry->migrationPaths(),
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
     * Allowed CORS origins = a hardcoded baseline of the first-party
     * *.tracht-digital.de production surfaces, merged with any extra origins from
     * CORS_ALLOWED_ORIGINS (deduped). The baseline means the widgets/frontends
     * always work even if the host `.env` is unset or stale — the env only ADDS
     * (e.g. http://localhost:4321 for dev). All baseline entries are TDS's own
     * domains; the live-chat-cta bubble on the public site + blog needs
     * tracht-digital.de + blog. here (it calls with credentials).
     *
     * @return string[]
     */
    private static function corsOrigins(): array
    {
        $baseline = [
            'https://tracht-digital.de',
            // The canonical landingpage is the apex, but a visitor who lands on
            // `www.` would post the contact form from an origin that is not on
            // this list — and a missing Access-Control-Allow-Origin is silent:
            // the browser drops the response, the form shows its generic
            // "try again later", and nothing is logged anywhere.
            'https://www.tracht-digital.de',
            'https://blog.tracht-digital.de',
            'https://management.tracht-digital.de',
            'https://app.tracht-digital.de',
            'https://tools.tracht-digital.de',
            'https://auth.tracht-digital.de',
        ];
        $raw = self::env('CORS_ALLOWED_ORIGINS', '');
        $extra = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique([...$baseline, ...$extra]));
    }
}
