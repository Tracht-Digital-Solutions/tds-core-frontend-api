<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\SiteConnectionStore;
use Tds\CoreFrontendApi\Service\SiteKeyPolicy;
use Tds\CoreFrontendApi\Service\SiteKeyStore;
use Tds\CoreFrontendApi\Service\SitePairingException;
use Tds\CoreFrontendApi\Service\SitePairingService;
use Tds\Frontend\Contract\SiteConnection;

/** Security and rollback contract of the two-phase public-site pairing. */
final class SitePairingServiceTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        SiteConnectionStore::resetSchemaFlagForTests();
        SiteKeyStore::resetSchemaFlagForTests();

        $dsn = getenv('TDS_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            return;
        }
        $this->pdo = new PDO(
            $dsn,
            (string) (getenv('TDS_TEST_DB_USER') ?: 'root'),
            (string) (getenv('TDS_TEST_DB_PASS') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->dropTables();
        }
    }

    public function testOnlyHttpsOriginsWithoutUrlExtrasAreAcceptedInProduction(): void
    {
        self::assertSame(
            'https://blog.tracht-digital.de',
            SitePairingService::origin('HTTPS://BLOG.TRACHT-DIGITAL.DE///'),
        );

        foreach ([
            'http://blog.tracht-digital.de',
            'https://user:secret@blog.tracht-digital.de',
            'https://blog.tracht-digital.de/path',
            'https://blog.tracht-digital.de?next=evil',
            'https://blog.tracht-digital.de#token',
            'https://localhost',
            'https://127.0.0.1',
            'https://internal.local',
            'https://intranet',
        ] as $origin) {
            try {
                SitePairingService::origin($origin);
                self::fail("Origin should have been rejected: {$origin}");
            } catch (SitePairingException $error) {
                self::assertSame(422, $error->httpStatus);
            }
        }
    }

    public function testLoopbackHttpIsAvailableOnlyForLocalDevelopment(): void
    {
        self::assertSame('http://localhost:4321', SitePairingService::origin('http://localhost:4321/', false, true));
        $this->expectException(SitePairingException::class);
        SitePairingService::origin('http://localhost:4321');
    }

    public function testResourceProfileAndScopeCannotBeBroadenedByACaller(): void
    {
        $service = $this->validationOnlyService();

        foreach ([
            ['blog', 'main', 'blog', ['blog' => 'other'], ['/content/blog']],
            ['blog', 'main', 'tools', ['blog' => 'main'], ['/content/blog']],
            ['blog', 'main', 'blog', ['blog' => 'main'], ['/tools/catalog']],
            ['website', 'landing', 'landingpage', ['website' => 'landing'], ['/content']],
        ] as [$type, $id, $profile, $bindings, $scopes]) {
            try {
                $service->createPairing($type, $id, 'http://localhost:4321', $profile, $bindings, $scopes);
                self::fail("Invalid pairing boundary should have been rejected for {$type}/{$profile}");
            } catch (SitePairingException $error) {
                self::assertSame(422, $error->httpStatus);
            }
        }
    }

    public function testDeliveryTransportFailureReturnsAFragmentOnlyFallback(): void
    {
        $service = $this->dbService(static function (): array {
            throw new \RuntimeException('network down');
        });
        $pairing = $service->createPairing(
            'blog',
            'hauptblog',
            'http://localhost:4321',
            'blog',
            ['blog' => 'hauptblog'],
            ['/content/blog'],
        );

        $delivery = $service->deliverPairing($pairing, 'http://localhost:8100');

        self::assertFalse($delivery->delivered);
        self::assertNotNull($delivery->fallbackUrl);
        $parts = parse_url((string) $delivery->fallbackUrl);
        self::assertArrayNotHasKey('query', $parts);
        self::assertStringContainsString('pairing_token=', (string) ($parts['fragment'] ?? ''));
        self::assertStringNotContainsString($pairing->pairingToken, (string) $delivery->error);
    }

    public function testExchangeRejectsAForgedApiOriginAndRemainsUsableAtThePinnedOne(): void
    {
        $service = $this->dbService(static fn (): array => ['status' => 503]);
        $pairing = $service->createPairing(
            'tools',
            'tools',
            'http://localhost:4322',
            'tools',
            ['tools' => 'tools'],
            ['/tools/catalog'],
        );
        $service->deliverPairing($pairing, 'http://localhost:8100');

        try {
            $service->exchange($pairing->pairingToken, 'tools', $pairing->origin, 'http://localhost:9999');
            self::fail('A Host/API-origin substitution must be rejected.');
        } catch (SitePairingException $error) {
            self::assertSame(403, $error->httpStatus);
            self::assertSame('pairing_api_mismatch', $error->errorCode);
        }

        $payload = $service->exchange($pairing->pairingToken, 'tools', $pairing->origin, 'http://localhost:8100');
        self::assertSame('http://localhost:8100', $payload['connection']['api_base']);
    }

    public function testPairingIsSingleUseHashOnlyAndFinalizeIsIdempotent(): void
    {
        $service = $this->dbService(static fn (): array => ['status' => 503]);
        $pairing = $service->createPairing(
            'blog',
            'hauptblog',
            'http://localhost:4321',
            'blog',
            ['blog' => 'hauptblog'],
            ['/content/blog', '/content/topics'],
        );
        $service->deliverPairing($pairing, 'http://localhost:8100');
        $payload = $service->exchange($pairing->pairingToken, 'blog', $pairing->origin, 'http://localhost:8100');

        self::assertStringStartsWith('tdsk_blog_', $payload['connection']['site_key']);
        self::assertStringStartsWith('tdsc_', $payload['connection']['cache_token']);
        $pairingRow = $this->pdo?->query('SELECT * FROM app_site_pairing')->fetch();
        self::assertIsArray($pairingRow);
        self::assertSame(hash('sha256', $pairing->pairingToken), $pairingRow['pairing_hash']);
        self::assertStringNotContainsString($pairing->pairingToken, json_encode($pairingRow, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($payload['connection']['site_key'], json_encode($pairingRow, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($payload['connection']['cache_token'], json_encode($pairingRow, JSON_THROW_ON_ERROR));

        try {
            $service->exchange($pairing->pairingToken, 'blog', $pairing->origin, 'http://localhost:8100');
            self::fail('A pairing token must be exchangeable only once.');
        } catch (SitePairingException $error) {
            self::assertSame(409, $error->httpStatus);
        }

        $first = $service->finalize(
            $payload['pairing_id'],
            $payload['finalize_token'],
            'blog',
            $pairing->origin,
        );
        $again = $service->finalize(
            $payload['pairing_id'],
            $payload['finalize_token'],
            'blog',
            $pairing->origin,
        );
        self::assertSame(SiteConnection::CONNECTED, $first->status);
        self::assertSame($first->id, $again->id);
    }

    public function testReconnectKeepsOldKeyUntilFinalizeThenRevokesIt(): void
    {
        $service = $this->dbService(static fn (): array => ['status' => 503]);
        [$oldPairing, $oldPayload] = $this->exchange($service, 'blog', 'hauptblog', 4321);
        $service->finalize($oldPayload['pairing_id'], $oldPayload['finalize_token'], 'blog', $oldPairing->origin);
        $keys = $this->keys();
        self::assertNotNull($keys->verify($oldPayload['connection']['site_key']));

        [$newPairing, $newPayload] = $this->exchange($service, 'blog', 'hauptblog', 4321);
        self::assertNotNull($keys->verify($oldPayload['connection']['site_key']), 'old connection remains active during phase one');
        self::assertNotNull($keys->verify($newPayload['connection']['site_key']), 'pending key can prove the site wrote it');

        $service->finalize($newPayload['pairing_id'], $newPayload['finalize_token'], 'blog', $newPairing->origin);
        self::assertNull($keys->verify($oldPayload['connection']['site_key']));
        self::assertNotNull($keys->verify($newPayload['connection']['site_key']));
    }

    public function testDisconnectCancelsPendingPairingsSoTheyCannotResurrectTheSite(): void
    {
        $service = $this->dbService(static fn (): array => ['status' => 503]);
        [$activePairing, $activePayload] = $this->exchange($service, 'blog', 'hauptblog', 4321);
        $service->finalize($activePayload['pairing_id'], $activePayload['finalize_token'], 'blog', $activePairing->origin);
        [$pendingPairing, $pendingPayload] = $this->exchange($service, 'blog', 'hauptblog', 4321);

        self::assertTrue($service->delete('blog', 'hauptblog'));
        self::assertNull($this->keys()->verify($activePayload['connection']['site_key']));
        self::assertNull($this->keys()->verify($pendingPayload['connection']['site_key']));

        try {
            $service->finalize($pendingPayload['pairing_id'], $pendingPayload['finalize_token'], 'blog', $pendingPairing->origin);
            self::fail('A disconnected resource must not be recreated by a pending invitation.');
        } catch (SitePairingException $error) {
            self::assertSame(410, $error->httpStatus);
            self::assertNull($service->get('blog', 'hauptblog'));
        }
    }

    public function testExpiredPairingIsCancelledAndCannotIssueAKey(): void
    {
        $service = $this->dbService(static fn (): array => ['status' => 503]);
        $pairing = $service->createPairing(
            'tools',
            'tools',
            'http://localhost:4322',
            'tools',
            ['tools' => 'tools'],
            ['/tools/catalog'],
        );
        $service->deliverPairing($pairing, 'http://localhost:8100');
        $this->pdo?->exec("UPDATE app_site_pairing SET expires_at = '2000-01-01 00:00:00'");

        try {
            $service->exchange($pairing->pairingToken, 'tools', $pairing->origin, 'http://localhost:8100');
            self::fail('Expired token must fail.');
        } catch (SitePairingException $error) {
            self::assertSame(410, $error->httpStatus);
        }
        self::assertSame(0, (int) $this->pdo?->query('SELECT COUNT(*) FROM app_site_key')->fetchColumn());
        self::assertNotNull($this->pdo?->query('SELECT cancelled_at FROM app_site_pairing')->fetchColumn());
    }

    private function validationOnlyService(): SitePairingService
    {
        $store = (new \ReflectionClass(SiteConnectionStore::class))->newInstanceWithoutConstructor();
        $keys = (new \ReflectionClass(SiteKeyStore::class))->newInstanceWithoutConstructor();
        return new SitePairingService($store, $keys, 'test-encryption-key', null, true);
    }

    private function dbService(?callable $http = null): SitePairingService
    {
        if ($this->pdo === null) {
            self::markTestSkipped('TDS_TEST_DB_DSN not set');
        }
        return new SitePairingService(
            new SiteConnectionStore($this->pdo, 'test-encryption-key'),
            $this->keys(),
            'test-encryption-key',
            null,
            true,
            null,
            $http,
        );
    }

    private function keys(): SiteKeyStore
    {
        if ($this->pdo === null) {
            self::markTestSkipped('TDS_TEST_DB_DSN not set');
        }
        return new SiteKeyStore($this->pdo, SiteKeyPolicy::resolve(
            null,
            static fn (string $key, ?string $default = null): string => (string) $default,
        ));
    }

    /** @return array{0:\Tds\Frontend\Contract\SitePairing,1:array<string,mixed>} */
    private function exchange(SitePairingService $service, string $type, string $id, int $port): array
    {
        $pairing = $service->createPairing(
            $type,
            $id,
            "http://localhost:{$port}",
            $type === 'website' ? 'landingpage' : $type,
            [$type => $id],
            $type === 'tools' ? ['/tools/catalog'] : ['/content/blog'],
        );
        $service->deliverPairing($pairing, 'http://localhost:8100');
        return [
            $pairing,
            $service->exchange($pairing->pairingToken, $pairing->profile, $pairing->origin, 'http://localhost:8100'),
        ];
    }

    private function dropTables(): void
    {
        $this->pdo?->exec('DROP TABLE IF EXISTS app_site_pairing');
        $this->pdo?->exec('DROP TABLE IF EXISTS app_site_connection');
        $this->pdo?->exec('DROP TABLE IF EXISTS app_site_key');
    }
}
