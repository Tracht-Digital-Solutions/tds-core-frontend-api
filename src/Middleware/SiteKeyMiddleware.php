<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Middleware;

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Tds\CoreFrontendApi\Service\SiteKeyPolicy;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;
use Tds\Frontend\Contract\SiteConnectionIdentity;
use Tds\Frontend\Contract\SiteKeys;
use Tds\Frontend\Contract\UserContext;

/**
 * Gates the **public site-read** routes on a site key.
 *
 * Which routes those are is not decided here: every module declares its own
 * through the contract's `SiteKeyProtected`, and the merged prefix list arrives
 * as a constructor argument. A coded path list in the base would rot on the
 * first rename, and it would rot *silently* — an unmatched prefix simply serves
 * the route unprotected.
 *
 * ### The container is touched only after a prefix matches
 *
 * `$container->get(SiteKeys::class)` builds the store, which resolves `PDO`,
 * which **connects**. This middleware is outermost-ish and sees every request,
 * so resolving unconditionally would put a database connection in front of the
 * entire API — and a hung one in front of it when the database is down. The
 * prefix comparison is pure string work and happens first. Same rule the CORS
 * middleware follows by taking a predicate instead of a resolved list.
 *
 * ### Order: added BEFORE AuthMiddleware, so it RUNS AFTER it
 *
 * Slim's stack is LIFO — the last `add()` runs first — which makes the ordering
 * here counter-intuitive and load-bearing in two directions at once:
 *
 *   - It must run **inside** `CorsMiddleware`, or the 401 goes out without
 *     `Access-Control-Allow-Origin` and the browser reports "blocked by CORS"
 *     for what is actually a rejected key, pointing every future debugging
 *     session at the wrong subsystem.
 *   - It must run **after** `AuthMiddleware`, because that is what populates
 *     `UserContext`. Added the other way round, the admin exemption below reads
 *     an anonymous context and never fires — so switching enforcement on would
 *     lock the panel out of the CMS preview, and nothing would say why.
 *
 * Net result: `add()` this one first, then Auth, then CORS.
 *
 * ### An admin session always passes
 *
 * The panel reads the same public routes (the CMS preview, the tools catalog).
 * Requiring a machine credential from a logged-in admin would break the panel
 * the moment enforcement is switched on, which is precisely when nobody is
 * looking for that.
 */
final class SiteKeyMiddleware implements MiddlewareInterface
{
    /** The header a server-side (build-time) caller presents. */
    public const HEADER = 'X-TDS-Site-Key';

    /** The body field a browser presents, so no preflight is needed for it. */
    public const BODY_FIELD = 'site_key';

    /**
     * @param list<string> $protectedPrefixes from ModuleRegistry::siteKeyRoutes()
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $protectedPrefixes,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The container lives for the worker lifetime. Reset first so a key
        // from the previous request can never bleed into the next one.
        $this->container->set(SiteConnectionIdentity::class, new SiteConnectionIdentity());
        $path = $request->getUri()->getPath();
        if ($this->protectedPrefixes === [] || !self::matches($path, $this->protectedPrefixes)) {
            return $handler->handle($request);
        }

        // A preflight carries no credentials by definition — rejecting it would
        // make the browser report a CORS failure for a route that is reachable.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $handler->handle($request);
        }

        $keys = $this->siteKeys();
        if ($keys === null) {
            // No key service (no database, older bundle) — nothing to enforce.
            return $handler->handle($request);
        }

        $presented = self::extractKey($request);
        if ($presented !== null) {
            $origin = $request->getHeaderLine('Origin');
            $identity = $keys->verify($presented, null, $origin !== '' ? $origin : null);
            if ($identity !== null && $identity->allows($path)) {
                $this->container->set(SiteConnectionIdentity::class, new SiteConnectionIdentity(
                    $identity->id,
                    $identity->site,
                    $identity->resourceType,
                    $identity->resourceId,
                    $identity->bindings,
                    $identity->scopes,
                ));
                return $handler->handle($request);
            }
        }

        $mode = $keys->enforcement();
        if ($mode === 'off') {
            return $handler->handle($request);
        }

        if ($this->isAdmin()) {
            return $handler->handle($request);
        }

        if ($mode === 'warn') {
            $this->recordUnkeyed($path, $request->getHeaderLine('Origin'));
            return $handler->handle($request);
        }

        return self::reject($presented !== null);
    }

    /** @param list<string> $prefixes */
    public static function matches(string $path, array $prefixes): bool
    {
        $path = rtrim($path, '/');
        foreach ($prefixes as $prefix) {
            // Prefix match on a SEGMENT boundary: `/content/blogged` must not be
            // covered by `/content/blog`. A plain str_starts_with would protect
            // — or, once the prefix is renamed, stop protecting — neighbouring
            // routes nobody meant to include.
            if ($path === $prefix || str_starts_with($path . '/', $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /** The presented plaintext, or null. Header first; body only for a browser call. */
    public static function extractKey(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine(self::HEADER));
        if ($header !== '') {
            return $header;
        }
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $value = trim((string) ($body[self::BODY_FIELD] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        // Deliberately NOT the query string: it lands in access logs, referrers
        // and browser history, and a credential in any of those outlives its use.
        return null;
    }

    private function siteKeys(): ?SiteKeys
    {
        try {
            return $this->container->has(SiteKeys::class)
                ? $this->container->get(SiteKeys::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAdmin(): bool
    {
        try {
            $user = $this->container->get(UserContext::class);
            return $user->isAuthenticated() && $user->isAdmin();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Count a keyless read so the panel can show coverage before enforcing.
     *
     * Best-effort by construction: this runs on a request that is being SERVED,
     * so a failure to write the counter must never change the response. The
     * shape is one JSON row rather than a counter table — at build-time volume
     * (a handful of reads per deploy) the read-modify-write costs nothing, and
     * one row keeps it inside the existing settings store.
     */
    private function recordUnkeyed(string $path, string $origin): void
    {
        error_log(sprintf(
            '[site-keys] keyless read of %s%s (mode=warn)',
            $path,
            $origin !== '' ? ' from ' . $origin : '',
        ));

        try {
            $store = $this->container->get(SettingsStoreContract::class);
            $raw = $store->get(SiteKeyPolicy::NAMESPACE, SiteKeyPolicy::KEY_UNKEYED, '') ?? '';
            $state = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            $state = is_array($state) ? $state : [];
        } catch (\Throwable) {
            $state = [];
        }

        $now = gmdate('c');
        $state = [
            'count' => (int) ($state['count'] ?? 0) + 1,
            'first_at' => (string) ($state['first_at'] ?? $now),
            'last_at' => $now,
            'last_path' => $path,
            'last_origin' => $origin,
        ];

        try {
            $this->container->get(SettingsStoreContract::class)->set(
                SiteKeyPolicy::NAMESPACE,
                SiteKeyPolicy::KEY_UNKEYED,
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                false,
            );
        } catch (\Throwable) {
            // Ignored: bookkeeping never fails a served request.
        }
    }

    private static function reject(bool $presented): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => $presented ? 'Invalid site key' : 'Site key required',
            // Pairing provisions the server-side credential. The browser and a
            // repository secret are deliberately not part of that path.
            'hint' => 'Die Site in den Einstellungen des zugehörigen CMS erneut mit der API verbinden.',
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
