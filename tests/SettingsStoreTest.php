<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\SettingsStore;

/**
 * Crypto round-trip (no DB) + admin-route gating through the REAL app. The
 * DB-backed get/set/mask paths skip without a database — the crypto and the auth
 * gate are the parts worth pinning here.
 */
final class SettingsStoreTest extends TestCase
{
    public function testEncryptRoundTrips(): void
    {
        $key = 'unit-test-key';
        $cipher = SettingsStore::encrypt('DEEPL-abcd-1234', $key);
        self::assertStringStartsWith('v1:', $cipher);
        self::assertNotSame('DEEPL-abcd-1234', $cipher);
        self::assertSame('DEEPL-abcd-1234', SettingsStore::decrypt($cipher, $key));
    }

    public function testDecryptRejectsWrongKeyAndGarbage(): void
    {
        $cipher = SettingsStore::encrypt('secret', 'right-key');
        self::assertNull(SettingsStore::decrypt($cipher, 'wrong-key'));
        self::assertNull(SettingsStore::decrypt('not-a-cipher', 'right-key'));
        self::assertNull(SettingsStore::decrypt('v1:!!!!', 'right-key'));
    }

    private function dbStore(string $key): SettingsStore
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run the DB-backed settings tests.');
        }
        $pdo = new \PDO($dsn, getenv('TDS_TEST_DB_USER') ?: null, getenv('TDS_TEST_DB_PASS') ?: null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('DROP TABLE IF EXISTS app_setting');
        SettingsStore::resetSchemaFlagForTests();
        return new SettingsStore($pdo, $key);
    }

    public function testRefusesASecretWithoutAnEncryptionKey(): void
    {
        // Encrypting under '' is encrypting under sha256(''), which anyone can
        // compute — the write must fail instead.
        $store = $this->dbStore('');
        $this->expectException(\Tds\CoreFrontendApi\Service\SettingsEncryptionUnavailable::class);
        $store->set('blog-cms', 'deepl_key', 'abc', true);
    }

    public function testASecretKeyStaysSecretWhateverTheCallerSays(): void
    {
        $store = $this->dbStore('unit-test-key');
        $store->set('mail', 'password', 'first', true);
        // The generic route takes the flag from the client; a plaintext
        // overwrite used to make getSecret() return null for good.
        $store->set('mail', 'password', 'second', false);

        self::assertSame('second', $store->getSecret('mail', 'password'));
        self::assertNull($store->get('mail', 'password'));
    }

    public function testAdminSettingsRequireAdmin(): void
    {
        // Anonymous (no token) → 401 on both read and write, before any DB touch.
        $app = Bootstrap::createApp(dirname(__DIR__));
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/settings/blog-cms')
        )->getStatusCode());
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/admin/settings/blog-cms')
                ->withParsedBody(['settings' => []])
        )->getStatusCode());
    }
}
