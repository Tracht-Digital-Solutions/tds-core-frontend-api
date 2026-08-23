<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Middleware\SiteKeyMiddleware;

/**
 * The prefix matcher is the whole gate: everything downstream only runs once it
 * says yes. Two of its properties fail silently in opposite directions, which
 * is why they are pinned here rather than reviewed —
 *
 *   - too NARROW and a protected route is served unprotected, looking exactly
 *     like a route somebody chose not to protect;
 *   - too WIDE and enforcement takes down a neighbouring route, on a public
 *     site, at the moment the mode is switched.
 */
final class SiteKeyMiddlewareTest extends TestCase
{
    private const PREFIXES = ['/content/blog', '/content/landing', '/tools/catalog'];

    public function testMatchesTheExactPrefix(): void
    {
        self::assertTrue(SiteKeyMiddleware::matches('/content/blog', self::PREFIXES));
    }

    public function testMatchesADeeperPathUnderThePrefix(): void
    {
        // The blog reads /content/blog/<slug> at build time; protecting only the
        // listing would leave every article body open.
        self::assertTrue(SiteKeyMiddleware::matches('/content/blog/mein-artikel', self::PREFIXES));
    }

    public function testMatchesRegardlessOfATrailingSlash(): void
    {
        self::assertTrue(SiteKeyMiddleware::matches('/content/blog/', self::PREFIXES));
    }

    public function testDoesNotMatchASiblingThatMerelyStartsTheSame(): void
    {
        // A plain str_starts_with would swallow this. `/content/blogroll` is a
        // different route and may belong to a different module.
        self::assertFalse(SiteKeyMiddleware::matches('/content/blogroll', self::PREFIXES));
    }

    public function testDoesNotMatchAnUnrelatedRoute(): void
    {
        self::assertFalse(SiteKeyMiddleware::matches('/me', self::PREFIXES));
        self::assertFalse(SiteKeyMiddleware::matches('/admin/settings/tools', self::PREFIXES));
        self::assertFalse(SiteKeyMiddleware::matches('/contact', self::PREFIXES));
    }

    public function testMatchesNothingWhenNoModuleDeclaredAnything(): void
    {
        self::assertFalse(SiteKeyMiddleware::matches('/content/blog', []));
    }

    public function testReadsTheKeyFromTheHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/content/blog')
            ->withHeader(SiteKeyMiddleware::HEADER, 'tdsk_blog_abc');
        self::assertSame('tdsk_blog_abc', SiteKeyMiddleware::extractKey($request));
    }

    public function testReadsTheKeyFromTheBodyForBrowserCalls(): void
    {
        // The /install wizard runs in a browser and posts the key in the body,
        // so no custom header means no extra preflight to get wrong.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/sites/handshake')
            ->withParsedBody([SiteKeyMiddleware::BODY_FIELD => 'tdsk_tools_xyz']);
        self::assertSame('tdsk_tools_xyz', SiteKeyMiddleware::extractKey($request));
    }

    public function testTheHeaderWinsOverTheBody(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/content/blog')
            ->withHeader(SiteKeyMiddleware::HEADER, 'from-header')
            ->withParsedBody([SiteKeyMiddleware::BODY_FIELD => 'from-body']);
        self::assertSame('from-header', SiteKeyMiddleware::extractKey($request));
    }

    public function testIgnoresTheQueryString(): void
    {
        // Deliberate: a credential in a query string lands in access logs,
        // referrers and browser history, and outlives the use it was sent for.
        // Accepting it "for convenience" is how it gets there.
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/content/blog?site_key=tdsk_blog_abc')
            ->withQueryParams(['site_key' => 'tdsk_blog_abc']);
        self::assertNull(SiteKeyMiddleware::extractKey($request));
    }

    public function testAnEmptyOrWhitespaceKeyIsNoKey(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/content/blog')
            ->withHeader(SiteKeyMiddleware::HEADER, '   ');
        self::assertNull(SiteKeyMiddleware::extractKey($request));
    }

    /**
     * The real app, with no database configured — the state a fresh host is in.
     *
     * A public read route must still be SERVED. The whole point of degrading to
     * "no keys" rather than "no access" is that a service without a database
     * must not answer 401 on every content route: the public sites would render
     * their baked fallbacks and report success, which is the failure mode this
     * feature exists to remove.
     */
    public function testAProtectedRouteIsStillServedWithoutADatabase(): void
    {
        $previous = $_ENV['DB_NAME'] ?? null;
        unset($_ENV['DB_NAME']);

        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/content/blog?limit=1&lang=de'),
        );

        self::assertNotSame(401, $response->getStatusCode());

        if ($previous !== null) {
            $_ENV['DB_NAME'] = $previous;
        }
    }

    /**
     * A preflight against a protected route must never be rejected here.
     *
     * It carries no credentials by definition, so gating it would make the
     * browser report a CORS failure for a route that is perfectly reachable —
     * and the symptom (an OPTIONS where you are looking for the real request)
     * points nowhere near this middleware.
     */
    public function testAPreflightAgainstAProtectedRouteStillGetsCorsHeaders(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://blog.tracht-digital.de';
        $app = Bootstrap::createApp(dirname(__DIR__));

        $response = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('OPTIONS', '/content/blog')
                ->withHeader('Origin', 'https://blog.tracht-digital.de')
                ->withHeader('Access-Control-Request-Method', 'GET'),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'https://blog.tracht-digital.de',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
        );

        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }

    /**
     * The header must be in the preflight's allow-list.
     *
     * Server-side build callers never preflight, so nothing today depends on
     * this — which is exactly why it would be dropped in a refactor and only
     * noticed by whoever next tries to send the key from a browser, as an
     * OPTIONS that never becomes a request.
     */
    public function testTheSiteKeyHeaderIsAllowedInPreflight(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://blog.tracht-digital.de';
        $app = Bootstrap::createApp(dirname(__DIR__));

        $response = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('OPTIONS', '/content/blog')
                ->withHeader('Origin', 'https://blog.tracht-digital.de')
                ->withHeader('Access-Control-Request-Method', 'GET')
                ->withHeader('Access-Control-Request-Headers', SiteKeyMiddleware::HEADER),
        );

        self::assertStringContainsString(
            SiteKeyMiddleware::HEADER,
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );

        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }
}
