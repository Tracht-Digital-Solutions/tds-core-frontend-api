<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\ModuleUpdateConfig;
use Tds\CoreFrontendApi\Service\PackageRegistry;
use Tds\CoreFrontendApi\Service\WorkflowDispatcher;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The Module page's backend: published-version lookup and the deploy dispatch
 * behind "Modul aktualisieren".
 *
 * Both talk to GitHub, so every test here injects the transport. What is worth
 * guarding is not the happy path — it is that this pair cannot become an
 * outbound HTTP proxy, cannot 500 a host that has no database yet, and reports
 * a failure the admin can act on instead of a bare boolean.
 */
final class ModuleUpdateTest extends TestCase
{
    // --- Route gating -------------------------------------------------------

    public function testCheckRequiresAuthentication(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/modules/check');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    public function testDeployRequiresAuthentication(): void
    {
        // A 404 here would mean the route was never mounted; a 200 would mean
        // anyone reaching the API could start a production deploy.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/modules/deploy');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    // --- PackageRegistry ----------------------------------------------------

    public function testRegistryRefusesForeignPackages(): void
    {
        // Without the allow-list the check endpoint is a generic outbound proxy
        // for anyone who reaches it — the classic SSRF shape.
        self::assertFalse(PackageRegistry::isAllowed('@evil/pkg'));
        self::assertFalse(PackageRegistry::isAllowed('http://169.254.169.254/'));
        self::assertFalse(PackageRegistry::isAllowed('@tracht-digital-solutions/../../etc'));
        self::assertTrue(PackageRegistry::isAllowed('@tracht-digital-solutions/tds-ext-blog-cms'));
    }

    public function testRegistryNeverCallsOutForADisallowedPackage(): void
    {
        $calls = 0;
        $registry = new PackageRegistry('tok', function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}', 'error' => ''];
        });

        self::assertNull($registry->latest('@evil/pkg'));
        self::assertSame(0, $calls);
    }

    public function testRegistryReadsTheLatestDistTag(): void
    {
        $seen = '';
        $registry = new PackageRegistry('tok', function (string $url) use (&$seen): array {
            $seen = $url;
            return [
                'status' => 200,
                'body' => json_encode(['dist-tags' => ['latest' => '0.1.29', 'dev' => '0.1.30-dev.1']]),
                'error' => '',
            ];
        });

        self::assertSame('0.1.29', $registry->latest('@tracht-digital-solutions/tds-ext-blog-cms'));
        // npm addresses a scoped package with the slash percent-encoded; the
        // unencoded form resolves to a different (404) route on the registry.
        self::assertStringContainsString('%2F', $seen);
    }

    public function testRegistryReportsAnUnreachableRegistryInsteadOfThrowing(): void
    {
        $registry = new PackageRegistry('tok', static fn (): array => [
            'status' => 401,
            'body' => '',
            'error' => '',
        ]);

        self::assertNull($registry->latest('@tracht-digital-solutions/tds-shared'));
        self::assertStringContainsString('401', $registry->lastError());
    }

    public function testRegistryKeepsARowForEveryRequestedPackage(): void
    {
        // The page renders one row per requested package either way — a missing
        // key would silently drop a module from the inventory.
        $registry = new PackageRegistry('tok', static fn (string $url): array => [
            'status' => str_contains($url, 'tds-shared') ? 404 : 200,
            'body' => json_encode(['dist-tags' => ['latest' => '1.0.0']]),
            'error' => '',
        ]);

        $out = $registry->latestMany([
            '@tracht-digital-solutions/tds-shared',
            '@tracht-digital-solutions/tds-ext-tools',
        ]);

        self::assertArrayHasKey('@tracht-digital-solutions/tds-shared', $out);
        self::assertNull($out['@tracht-digital-solutions/tds-shared']);
        self::assertSame('1.0.0', $out['@tracht-digital-solutions/tds-ext-tools']);
    }

    public function testUnconfiguredRegistryLooksUpNothing(): void
    {
        $registry = new PackageRegistry('');
        self::assertFalse($registry->isConfigured());
        self::assertNull($registry->latest('@tracht-digital-solutions/tds-shared'));
    }

    // --- WorkflowDispatcher -------------------------------------------------

    public function testDispatchSucceedsOn204(): void
    {
        $payload = '';
        $dispatcher = new WorkflowDispatcher('tok', function (string $url, array $h, string $body) use (&$payload): array {
            $payload = $body;
            return ['status' => 204, 'body' => '', 'error' => ''];
        });

        $result = $dispatcher->dispatch('Tracht-Digital-Solutions/tds-admin-frontend', 'release.yml', 'main');
        self::assertTrue($result['ok']);
        // Only `ref` — the endpoint 422s on an input the workflow does not declare.
        self::assertSame(['ref' => 'main'], json_decode($payload, true));
    }

    public function testDispatchRejectsAMalformedRepoWithoutCallingGitHub(): void
    {
        $calls = 0;
        $dispatcher = new WorkflowDispatcher('tok', function () use (&$calls): array {
            $calls++;
            return ['status' => 204, 'body' => '', 'error' => ''];
        });

        self::assertFalse($dispatcher->dispatch('not-a-repo', 'release.yml')['ok']);
        self::assertFalse($dispatcher->dispatch('owner/name', 'release')['ok']);
        self::assertSame(0, $calls);
    }

    public function testDispatchExplainsARejectedToken(): void
    {
        // "403" alone sends the admin looking in the wrong place; the missing
        // scope / missing SSO authorisation is the actual fix.
        $dispatcher = new WorkflowDispatcher('tok', static fn (): array => [
            'status' => 403,
            'body' => '',
            'error' => '',
        ]);

        $result = $dispatcher->dispatch('Tracht-Digital-Solutions/tds-gateway-api', 'release.yml');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('workflow', $result['message']);
    }

    public function testUnconfiguredDispatcherFailsWithAReason(): void
    {
        $result = (new WorkflowDispatcher(''))->dispatch('owner/name', 'release.yml');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Token', $result['message']);
    }

    // --- ModuleUpdateConfig -------------------------------------------------

    public function testConfigFallsBackToEnvAndDefaults(): void
    {
        $config = ModuleUpdateConfig::resolve(null, static fn (string $k, ?string $d): string => (string) $d);

        self::assertSame('release.yml', $config->frontendWorkflow);
        self::assertSame('Tracht-Digital-Solutions/tds-gateway-api', $config->backendRepo);
        self::assertSame('main', $config->ref);
        // No token, no repo ⇒ nothing is dispatchable.
        self::assertNull($config->target('frontend'));
        self::assertNull($config->target('backend'));
    }

    public function testStoredValuesWinOverEnv(): void
    {
        $store = $this->store([
            'registry_token' => 'from-db',
            'frontend_repo' => 'Tracht-Digital-Solutions/tds-admin-frontend',
        ]);
        // Env supplies the same two keys the DB does, so a stored value winning
        // is observable; everything else falls through to its coded default.
        $config = ModuleUpdateConfig::resolve($store, static fn (string $k, ?string $d): string => match ($k) {
            'MODULES_REGISTRY_TOKEN' => 'from-env',
            'MODULES_FRONTEND_REPO' => 'Tracht-Digital-Solutions/from-env',
            default => (string) $d,
        });

        self::assertSame('from-db', $config->registryToken);
        // One PAT usually carries both scopes — an unset dispatch token reuses it.
        self::assertSame('from-db', $config->dispatchToken);
        self::assertSame(
            ['repo' => 'Tracht-Digital-Solutions/tds-admin-frontend', 'workflow' => 'release.yml'],
            $config->target('frontend'),
        );
    }

    public function testConfigSurvivesAStoreThatThrows(): void
    {
        // The state the frontend service is in until services/frontend/.env +
        // the tds_frontend DB exist — which is exactly when an admin opens this
        // page. A throw here would 500 the one screen that explains the problem.
        $store = new class implements SettingsStoreContract {
            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                throw new \RuntimeException('no database');
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                throw new \RuntimeException('no database');
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
            }

            public function delete(string $namespace, string $key): void
            {
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };

        $config = ModuleUpdateConfig::resolve($store, static fn (string $k, ?string $d): string => (string) $d);
        self::assertSame('', $config->registryToken);
        self::assertSame('main', $config->ref);
    }

    public function testDeclaredKeysCoverTheFormFields(): void
    {
        $keys = array_column(ModuleUpdateConfig::declaredKeys(), 'key');
        self::assertSame([
            'registry_token',
            'dispatch_token',
            'frontend_repo',
            'frontend_workflow',
            'backend_repo',
            'backend_workflow',
            'ref',
            'auto_update',
            'auto_update_interval',
        ], $keys);

        $secrets = array_column(
            array_filter(ModuleUpdateConfig::declaredKeys(), static fn (array $k): bool => $k['secret']),
            'key',
        );
        self::assertSame(['registry_token', 'dispatch_token'], $secrets);
    }

    /** @param array<string,string> $values */
    private function store(array $values): SettingsStoreContract
    {
        return new class ($values) implements SettingsStoreContract {
            /** @param array<string,string> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                return $this->values[$key] ?? $default;
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
            }

            public function delete(string $namespace, string $key): void
            {
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };
    }
}
