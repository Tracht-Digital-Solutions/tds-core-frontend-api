<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\SiteKeyStore;

/**
 * The key store. Issue / verify / revoke run against a real database when one
 * is configured (`TDS_TEST_DB_DSN`), and are skipped otherwise — the DDL and
 * the admin gate below need none and always run.
 *
 * Two things here are load-bearing rather than incidental:
 *
 *   - the **DDL** carries an explicit `NOT NULL` on the primary key. MariaDB
 *     (dev, CI, every DB-backed test) silently coerces a nullable PK column;
 *     MySQL 8 — the production host — rejects the whole statement with
 *     SQLSTATE 1171. That asymmetry already cost a release once, when
 *     `/install.php` died mid-migration on a fresh host with ten migrations
 *     unapplied and no way to continue. This table is hand-written DDL outside
 *     Phinx, so the gateway's MySQL 8 rehearsal does not cover it.
 *   - only a **hash** is persisted. A store that kept the plaintext would work
 *     identically in every test here and be a different feature.
 */
final class SiteKeyStoreTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
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
        $this->pdo->exec('DROP TABLE IF EXISTS app_site_key');
    }

    private function store(): SiteKeyStore
    {
        if ($this->pdo === null) {
            self::markTestSkipped('TDS_TEST_DB_DSN not set');
        }
        // Enforcement is irrelevant to the store's own behaviour; the policy is
        // covered by SiteKeyPolicyTest.
        return new SiteKeyStore($this->pdo, \Tds\CoreFrontendApi\Service\SiteKeyPolicy::resolve(
            null,
            static fn (string $k, ?string $d = null): string => (string) $d,
        ));
    }

    public function testTheDdlDeclaresThePrimaryKeyNotNull(): void
    {
        // Static, no DB: MariaDB would accept the broken form and the prod host
        // would not, so a runtime test proves nothing about production.
        $source = file_get_contents(dirname(__DIR__) . '/src/Service/SiteKeyStore.php');
        self::assertIsString($source);
        self::assertStringContainsString('id INT UNSIGNED NOT NULL AUTO_INCREMENT', $source);
        self::assertStringContainsString('PRIMARY KEY (id)', $source);
    }

    public function testAnIssuedKeyVerifies(): void
    {
        $store = $this->store();
        $issued = $store->issue('blog', 'Blog', 'https://blog.tracht-digital.de');

        self::assertStringStartsWith('tdsk_blog_', $issued['key']);

        $identity = $store->verify($issued['key']);
        self::assertNotNull($identity);
        self::assertSame('blog', $identity->site);
        self::assertSame($issued['id'], $identity->id);
    }

    public function testOnlyAHashIsPersisted(): void
    {
        $store = $this->store();
        $issued = $store->issue('tools');

        $row = $this->pdo->query('SELECT key_hash, key_prefix FROM app_site_key')->fetch();
        self::assertSame(hash('sha256', $issued['key']), $row['key_hash']);
        // The prefix is short enough to identify a key and far too short to be
        // one: it exists so the panel can say WHICH key, not to be used.
        self::assertStringStartsWith($row['key_prefix'], $issued['key']);
        self::assertLessThan(strlen($issued['key']), strlen($row['key_prefix']));
    }

    public function testAKeyForAnotherSiteIsRejectedWhenTheSiteIsDemanded(): void
    {
        // POST /tools/registry passes the site, precisely so a blog key cannot
        // write the tools catalog. Trusting a `site` field sent alongside the
        // key would make this check meaningless.
        $store = $this->store();
        $blog = $store->issue('blog');

        self::assertNotNull($store->verify($blog['key'], 'blog'));
        self::assertNull($store->verify($blog['key'], 'tools'));
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $store = $this->store();
        $store->issue('blog');
        self::assertNull($store->verify('tdsk_blog_definitelynotit'));
        self::assertNull($store->verify(''));
    }

    public function testARevokedKeyStopsVerifyingButStaysListed(): void
    {
        $store = $this->store();
        $issued = $store->issue('landingpage');

        self::assertTrue($store->revoke($issued['id']));
        self::assertNull($store->verify($issued['key']));

        // Still listed: "this site had a key and it was revoked on the 3rd" is
        // the question the panel exists to answer, and a vanished row answers
        // nothing.
        $rows = $store->all();
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]['revoked_at']);
    }

    public function testRevokingTwiceReportsFalse(): void
    {
        $store = $this->store();
        $issued = $store->issue('blog');
        self::assertTrue($store->revoke($issued['id']));
        self::assertFalse($store->revoke($issued['id']));
        self::assertFalse($store->revoke(999999));
    }

    public function testVerifyingRecordsTheUse(): void
    {
        // Verification and recording are one operation on purpose: a caller
        // that had to remember a second touch() would eventually forget, and
        // "last seen" is the panel's only evidence a site is really connected.
        $store = $this->store();
        $issued = $store->issue('tools');
        self::assertNull($store->all()[0]['last_used_at']);

        $store->verify($issued['key'], null, 'https://tools.tracht-digital.de');

        $row = $store->all()[0];
        self::assertNotNull($row['last_used_at']);
        self::assertSame('https://tools.tracht-digital.de', $row['last_used_origin']);
    }

    public function testTouchKeepsTheLastKnownValueWhenGivenNothing(): void
    {
        // A build-time call has no Origin header. It must not erase the origin
        // a browser handshake recorded — an empty field reads as "never seen".
        $store = $this->store();
        $issued = $store->issue('blog');
        $store->touch($issued['id'], 'https://blog.tracht-digital.de', 'https://api.tracht-digital.de');
        $store->touch($issued['id'], null, null);

        $row = $store->all()[0];
        self::assertSame('https://blog.tracht-digital.de', $row['last_used_origin']);
        self::assertSame('https://api.tracht-digital.de', $row['last_used_api_base']);
    }

    public function testTwoKeysForOneSiteCoexist(): void
    {
        // Rotation is: issue the new one, deploy it, revoke the old one. A
        // store that allowed one key per site would make that a downtime.
        $store = $this->store();
        $old = $store->issue('blog', 'alt');
        $new = $store->issue('blog', 'neu');

        self::assertNotSame($old['key'], $new['key']);
        self::assertNotNull($store->verify($old['key']));
        self::assertNotNull($store->verify($new['key']));
        self::assertCount(2, $store->all());
    }

    public function testTheAdminRoutesRequireAnAdmin(): void
    {
        // No DB needed: the gate runs before anything is read.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $factory = new ServerRequestFactory();

        self::assertSame(401, $app->handle($factory->createServerRequest('GET', '/admin/sites'))->getStatusCode());
        self::assertSame(401, $app->handle(
            $factory->createServerRequest('POST', '/admin/sites')->withParsedBody(['site' => 'blog']),
        )->getStatusCode());
        self::assertSame(401, $app->handle(
            $factory->createServerRequest('PUT', '/admin/sites')->withParsedBody(['enforcement' => 'off']),
        )->getStatusCode());
        self::assertSame(401, $app->handle($factory->createServerRequest('DELETE', '/admin/sites/1'))->getStatusCode());
    }

    public function testTheHandshakeRejectsAnUnknownKeyWithout401ingOnMissingDatabase(): void
    {
        // Public route: it must answer 401 "rejected", never 500. An operator
        // running /install on a host whose DB is not up yet needs a message,
        // not a stack trace.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/sites/handshake')
                ->withParsedBody(['key' => 'tdsk_blog_nope', 'site' => 'blog']),
        );
        self::assertSame(401, $response->getStatusCode());
    }
}
