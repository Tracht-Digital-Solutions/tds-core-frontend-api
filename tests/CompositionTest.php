<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;

/**
 * Composition end-to-end through the REAL app: the base boots, composes the
 * enabled modules, mounts their routes, and surfaces the merged catalog.
 */
final class CompositionTest extends TestCase
{
    public function testHealthListsComposedModules(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/healthz');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertContains('time-tracker', $body['modules']);
        self::assertContains('lexware', $body['modules']);
        self::assertContains('customers', $body['modules']);
        self::assertContains('billing', $body['modules']);
    }

    public function testAdminPermissionsExposesMergedCatalog(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/permissions');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column(
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
            'id',
        );
        self::assertContains('time:read', $ids);
        self::assertContains('lexware:read', $ids);
        // `companies:read`, not `customers:read`: the customers extension renamed
        // its permissions with the company directory and keeps the old spelling
        // only as an inbound alias for tokens issued before the rename. This
        // catalog is the authoritative list an enforcing service publishes, so it
        // carries the current name.
        self::assertContains('companies:read', $ids);
        self::assertContains('billing:read', $ids);
    }

    public function testModuleRouteIsMounted(): void
    {
        // The composed time-tracker mounts /time/summary and gates it via the core
        // UserContext — anonymous → 401 (a 404 would mean the route wasn't injected).
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/time/summary');
        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLexwareRouteIsMounted(): void
    {
        // The composed lexware module mounts /lexware/summary and gates it via
        // the core UserContext — anonymous → 401 (404 would mean not injected).
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/lexware/summary');
        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testCustomersRouteIsMounted(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/customers/summary');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    public function testBillingRouteIsMounted(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/billing/summary');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    /**
     * The CMS + ticket modules moved off the archived content/contact backends
     * into the composed API — their summary routes must be mounted (401 anon,
     * not 404). This is what the gateway's catch-all + the public sites hit.
     *
     * @dataProvider composedSummaryRoutes
     */
    public function testComposedModuleRouteIsMounted(string $route): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $route);
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    public static function composedSummaryRoutes(): array
    {
        return [
            'support-tickets' => ['/tickets/summary'],
            'contact-tickets' => ['/contact/summary'],
            'website-cms' => ['/cms/summary'],
            'blog-cms' => ['/blog/summary'],
            'messages' => ['/messages/summary'],
            'projects' => ['/projects/summary'],
            'documents' => ['/documents/summary'],
            'tools' => ['/tools/summary'],
        ];
    }

    /**
     * The public content-delivery routes (successors to the archived
     * content-api's open read) are UNAUTHENTICATED — anonymous must NOT get 401.
     * They degrade to an empty payload when the DB is unreachable (as here, no
     * DB), which is exactly the build-fetch fail-safe, so they answer 200.
     *
     * @dataProvider publicContentRoutes
     */
    public function testPublicContentRouteIsUnauthenticated(string $route): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $route);
        $status = $app->handle($request)->getStatusCode();
        self::assertNotSame(401, $status, "$route must be public (not auth-gated)");
        self::assertSame(200, $status, "$route should degrade to 200 empty with no DB");
    }

    public static function publicContentRoutes(): array
    {
        return [
            'blog list' => ['/content/blog'],
            'blog popular' => ['/content/blog/popular'],
            'blog topics' => ['/content/topics'],
            'blog snippets' => ['/content/snippets'],
            'landing blocks' => ['/content/landing'],
        ];
    }

    public function testPublicBlogPostMissingIs404NotAuth(): void
    {
        // A single-post read for an unknown slug is a public 404 (never 401).
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/content/blog/does-not-exist');
        self::assertSame(404, $app->handle($request)->getStatusCode());
    }

    public function testWikiJsonRequiresAdmin(): void
    {
        // No token → anonymous → 401 (the admin route map is not public). The
        // admin 200 path needs a real JWT, out of scope for the no-DB suite.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/wiki.json'));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testDashboardLayoutRequiresAuth(): void
    {
        // No token → anonymous → 401 on both read and write, before any DB touch.
        $app = Bootstrap::createApp(dirname(__DIR__));
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/me/dashboard-layout')
        )->getStatusCode());
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/me/dashboard-layout')
                ->withParsedBody(['layout' => []])
        )->getStatusCode());
    }
}
