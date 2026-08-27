<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Modules;
use Tds\CoreFrontendApi\Service\ApiReference;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\ModuleRegistry;

/** A module that mounts routes and documents (some of) them. */
final class DocumentedFake extends AbstractModule implements ApiDocSource
{
    /**
     * @param list<array{0: string, 1: string}> $routes
     * @param list<array<string, mixed>>        $docs
     */
    public function __construct(
        private readonly string $id,
        private readonly array $routes,
        private readonly array $docs,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function register(App $app): void
    {
        foreach ($this->routes as [$method, $pattern]) {
            $app->map([$method], $pattern, static fn ($req, $res) => $res);
        }
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        return $this->docs;
    }
}

/** A module whose docs blow up — must cost only its own prose. */
final class ThrowingDocsFake extends AbstractModule implements ApiDocSource
{
    public function id(): string
    {
        return 'throwing';
    }

    public function register(App $app): void
    {
        $app->get('/throwing/thing', static fn ($req, $res) => $res);
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * The API reference (`GET /wiki.json`) — the merge of route introspection with
 * the prose each module contributes.
 *
 * The parity tests here are the reason the reference can be trusted: prose that
 * sits next to code rots, and a reference full of confident, wrong detail is
 * worse than the bare route list it replaced. Renaming a path now fails the
 * suite instead of quietly leaving a description behind.
 */
final class ApiReferenceTest extends TestCase
{
    /** Every route key ("<METHOD> <pattern>") the enabled modules mount. */
    private static function moduleRouteKeys(): ModuleRegistry
    {
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry(Modules::enabled());
        $registry->registerAll($app);
        return $registry;
    }

    /** @return string[] */
    private static function realRouteKeys(): array
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $keys = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $keys[] = strtoupper($method) . ' ' . $route->getPattern();
            }
        }
        return $keys;
    }

    public function testEveryBaseRouteIsDocumented(): void
    {
        // The base's own routes are whatever the composed app has that no module
        // claimed. They are the routes every frontend depends on, so they are
        // the last place that should be blank.
        $owned = array_keys(self::moduleRouteKeys()->routeOwners());
        $baseRoutes = array_values(array_diff(self::realRouteKeys(), $owned));
        $documented = array_map(
            static fn (array $d): string => strtoupper((string) $d['method']) . ' ' . $d['pattern'],
            ApiReference::baseDocs(),
        );

        sort($baseRoutes);
        sort($documented);
        self::assertSame($baseRoutes, $documented);
    }

    public function testBaseDocEntriesAreWellFormed(): void
    {
        foreach (ApiReference::baseDocs() as $doc) {
            $where = $doc['method'] . ' ' . $doc['pattern'];
            self::assertNotSame('', trim((string) $doc['summary']), "Leere Zusammenfassung: {$where}");
            self::assertContains(
                $doc['auth'] ?? 'public',
                ['public', 'session', 'permission', 'admin', 'token', 'pairing-token', 'finalize-token'],
                "Unbekannter auth-Wert: {$where}",
            );
            foreach ($doc['params'] ?? [] as $param) {
                self::assertContains($param['in'], ['path', 'query', 'body', 'header'], "Unbekanntes in: {$where}");
                self::assertNotSame('', trim((string) $param['name']), "Parameter ohne Namen: {$where}");
            }
            foreach ($doc['responses'] ?? [] as $response) {
                self::assertIsInt($response['status'], "Status ist kein int: {$where}");
                self::assertNotSame('', trim((string) $response['description']), "Antwort ohne Text: {$where}");
            }
        }
    }

    public function testEveryComposedModuleDocumentsExactlyItsOwnRoutes(): void
    {
        // Per module, so a failure names the repo that has to fix it. Both
        // directions matter: a missing entry leaves a blank row in the
        // reference, an extra one means a path was renamed and the prose stayed.
        $undocumented = [];
        foreach (Modules::enabled() as $module) {
            if (!$module instanceof ApiDocSource) {
                $undocumented[] = $module->id();
                continue;
            }

            $app = AppFactory::createFromContainer(new Container());
            $registry = new ModuleRegistry([$module]);
            $registry->registerAll($app);
            $mounted = array_keys($registry->routeOwners());

            $documented = array_map(
                static fn (array $d): string => strtoupper((string) $d['method']) . ' ' . $d['pattern'],
                $module->apiDocs(),
            );
            sort($mounted);
            sort($documented);
            self::assertSame($mounted, $documented, "API-Doku weicht ab in Modul \"{$module->id()}\"");
        }

        // Reported at the END, so the modules that ARE documented still get
        // asserted while the rollout is in progress.
        if ($undocumented !== []) {
            self::markTestIncomplete('Ohne API-Doku: ' . implode(', ', $undocumented));
        }
    }

    public function testGroupsByOwningModuleNotByPathSegment(): void
    {
        // The old version grouped by first path segment, which put all modules'
        // /admin/* routes in one bucket — the single thing that made the page
        // useless as a reference.
        $app = AppFactory::createFromContainer(new Container());
        $app->get('/base-only', static fn ($req, $res) => $res);
        $registry = new ModuleRegistry([
            new DocumentedFake('alpha', [['GET', '/admin/alpha']], [
                ['method' => 'GET', 'pattern' => '/admin/alpha', 'summary' => 'A'],
            ]),
            new DocumentedFake('beta', [['GET', '/admin/beta']], [
                ['method' => 'GET', 'pattern' => '/admin/beta', 'summary' => 'B'],
            ]),
        ]);
        $registry->registerAll($app);

        $payload = (new ApiReference($app, $registry))->build();
        $ids = array_column($payload['modules'], 'id');

        self::assertSame(['base', 'alpha', 'beta'], $ids);
        self::assertSame('/base-only', $payload['modules'][0]['routes'][0]['pattern']);
        self::assertSame('/admin/alpha', $payload['modules'][1]['routes'][0]['pattern']);
    }

    public function testUndocumentedRoutesStillAppear(): void
    {
        // Introspection is authoritative: nobody can shrink the reference by
        // forgetting to write something down.
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry([
            new DocumentedFake('alpha', [['GET', '/a'], ['POST', '/a']], [
                ['method' => 'GET', 'pattern' => '/a', 'summary' => 'Lesen'],
            ]),
        ]);
        $registry->registerAll($app);

        $payload = (new ApiReference($app, $registry))->build();
        $routes = $payload['modules'][0]['routes'];

        self::assertCount(2, $routes);
        self::assertTrue($routes[0]['documented']);
        self::assertFalse($routes[1]['documented']);
        // Nothing is claimed about an undocumented route — not even that it is public.
        self::assertArrayNotHasKey('auth', $routes[1]);
        self::assertSame(2, $payload['stats']['routes']);
        self::assertSame(1, $payload['stats']['documented']);
    }

    public function testADocWithoutARouteIsReportedNotDropped(): void
    {
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry([
            new DocumentedFake('alpha', [['GET', '/a']], [
                ['method' => 'GET', 'pattern' => '/a', 'summary' => 'Lesen'],
                ['method' => 'GET', 'pattern' => '/umbenannt', 'summary' => 'Alter Pfad'],
            ]),
        ]);
        $registry->registerAll($app);

        // The base's own docs are orphans here — this app mounts no base routes.
        // Subtract them so the assertion is about the module's stale entry.
        $baseKeys = array_map(
            static fn (array $d): string => strtoupper((string) $d['method']) . ' ' . $d['pattern'],
            ApiReference::baseDocs(),
        );
        $payload = (new ApiReference($app, $registry))->build();
        $orphans = array_values(array_diff($payload['stats']['orphan_docs'], $baseKeys));

        self::assertSame(['GET /umbenannt'], $orphans);
    }

    public function testAThrowingDocSourceCostsOnlyItsOwnProse(): void
    {
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry([
            new ThrowingDocsFake(),
            new DocumentedFake('alpha', [['GET', '/a']], [
                ['method' => 'GET', 'pattern' => '/a', 'summary' => 'Lesen'],
            ]),
        ]);
        $registry->registerAll($app);

        $payload = (new ApiReference($app, $registry))->build();
        self::assertSame(['throwing', 'alpha'], array_column($payload['modules'], 'id'));
        self::assertFalse($payload['modules'][0]['routes'][0]['documented']);
        self::assertTrue($payload['modules'][1]['routes'][0]['documented']);
    }

    public function testAuthDefaultsToPermissionWhenOneIsNamed(): void
    {
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry([
            new DocumentedFake('alpha', [['GET', '/a']], [
                ['method' => 'GET', 'pattern' => '/a', 'summary' => 'L', 'permission' => 'a:read'],
            ]),
        ]);
        $registry->registerAll($app);

        $route = (new ApiReference($app, $registry))->build()['modules'][0]['routes'][0];
        self::assertSame('permission', $route['auth']);
        self::assertSame('a:read', $route['permission']);
    }
}
