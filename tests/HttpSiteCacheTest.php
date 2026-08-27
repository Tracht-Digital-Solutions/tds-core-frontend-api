<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\HttpSiteCache;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\CacheResult;

/**
 * The base's SiteCache implementation.
 *
 * The load-bearing property is not what it sends but what it REFUSES to do:
 * this runs inside the request that saved a block, a post or a guide, and a
 * public site being unreachable must never turn that save into an error. Every
 * failure path below asserts silence, not a message.
 */
final class HttpSiteCacheTest extends TestCase
{
    /** @var list<array{url:string,headers:array,body:string}> */
    private array $sent = [];

    private function cache(
        int $status = 200,
        string $error = '',
        string $responseBody = '{"rebuilt":["/"],"skipped":[],"failed":[],"unknownEvents":[]}',
    ): HttpSiteCache
    {
        return new HttpSiteCache(function (string $url, array $headers, string $body) use ($status, $error, $responseBody): array {
            $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            return ['status' => $status, 'error' => $error, 'body' => $responseBody];
        });
    }

    public function testPostsTheEventsToTheSitesRebuildEndpoint(): void
    {
        $this->cache()->rebuild('https://blog.tracht-digital.de', 'tok', [
            new CacheEvent('post', 'mein-artikel', 'de'),
        ]);

        self::assertCount(1, $this->sent);
        self::assertSame('https://blog.tracht-digital.de/tds/cache/rebuild', $this->sent[0]['url']);
        self::assertSame(
            ['events' => [['type' => 'post', 'id' => 'mein-artikel', 'lang' => 'de']]],
            json_decode($this->sent[0]['body'], true),
        );
    }

    public function testSendsJsonContentTypeBecauseAstroRejectsAnythingElse(): void
    {
        // Not cosmetic. The receiving endpoint is an Astro route, and Astro's
        // security.checkOrigin treats a cross-site POST with a form-ish content
        // type as CSRF: "Cross-site POST form submissions are forbidden" — a
        // message that says nothing about content types.
        $this->cache()->rebuild('https://tools.tracht-digital.de', 'tok', [new CacheEvent('tool')]);

        self::assertContains('Content-Type: application/json', $this->sent[0]['headers']);
        self::assertContains('X-TDS-Cache-Token: tok', $this->sent[0]['headers']);
    }

    public function testTrimsATrailingSlashOffTheConfiguredBase(): void
    {
        // The commonest thing an operator pastes. A doubled slash is a
        // different path: the site would answer 404 and the panel would report
        // a green save with a red log line nobody reads.
        $this->cache()->rebuild('https://blog.tracht-digital.de/', 'tok', [new CacheEvent('post', 'x')]);
        self::assertSame('https://blog.tracht-digital.de/tds/cache/rebuild', $this->sent[0]['url']);
    }

    public function testAcceptsOnlyAnOriginWithoutCredentialsPathQueryOrFragment(): void
    {
        $cache = $this->cache();
        $events = [new CacheEvent('post', 'x')];

        foreach ([
            'https://user:secret@blog.tracht-digital.de',
            'https://blog.tracht-digital.de/subdirectory',
            'https://blog.tracht-digital.de?next=https://attacker.example',
            'https://blog.tracht-digital.de#fragment',
            'https://blog.tracht-digital.de\\subdirectory',
            "https://blog.tracht-digital.de\n.evil.example",
        ] as $base) {
            self::assertFalse($cache->isConfigured($base, 'tok'), $base);
            $cache->rebuild($base, 'tok', $events);
        }

        self::assertSame([], $this->sent);
    }

    public function testNormalisesOriginCaseAndRepeatedTrailingSlashes(): void
    {
        $this->cache()->rebuild('HTTPS://BLOG.TRACHT-DIGITAL.DE///', 'tok', [new CacheEvent('post', 'x')]);
        self::assertSame('https://blog.tracht-digital.de/tds/cache/rebuild', $this->sent[0]['url']);
    }

    public function testCurlTransportNeverFollowsARedirectWithTheSecretHeader(): void
    {
        // CURLOPT_HTTPHEADER is reused by libcurl after redirects, including a
        // redirect to another host. Source-level because the injected test
        // transport intentionally sits above curl itself.
        $source = file_get_contents(__DIR__ . '/../src/Service/HttpSiteCache.php');
        self::assertIsString($source);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
        self::assertStringNotContainsString('CURLOPT_MAXREDIRS', $source);
    }

    public function testSendsNothingWhenTheSiteIsNotConfigured(): void
    {
        $cache = $this->cache();
        $events = [new CacheEvent('post', 'x')];

        $cache->rebuild('', 'tok', $events);
        $cache->rebuild('https://blog.tracht-digital.de', null, $events);
        $cache->rebuild('https://blog.tracht-digital.de', '', $events);
        // Not a URL at all — a half-typed setting must not become a request.
        $cache->rebuild('blog.tracht-digital.de', 'tok', $events);

        self::assertSame([], $this->sent);
    }

    public function testSendsNothingForAnEmptyEventList(): void
    {
        $this->cache()->rebuild('https://blog.tracht-digital.de', 'tok', []);
        self::assertSame([], $this->sent);
    }

    public function testAnUnreachableSiteDoesNotThrow(): void
    {
        // The whole point. The article is saved either way; the public page
        // stays stale and the panel has a rebuild button to catch up.
        $this->expectNotToPerformAssertions();
        $this->cache(0, 'Could not resolve host')
            ->rebuild('https://blog.tracht-digital.de', 'tok', [new CacheEvent('post', 'x')]);
    }

    public function testARejectedTokenDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->cache(401)->rebuild('https://blog.tracht-digital.de', 'tok', [new CacheEvent('post', 'x')]);
    }

    public function testIsConfiguredNeedsBothHalves(): void
    {
        $cache = $this->cache();

        self::assertTrue($cache->isConfigured('https://blog.tracht-digital.de', 'tok'));
        self::assertFalse($cache->isConfigured('https://blog.tracht-digital.de', ''));
        self::assertFalse($cache->isConfigured('', 'tok'));
        self::assertFalse($cache->isConfigured('nonsense', 'tok'));
    }

    public function testReportsPartialAndUnknownEventFailuresTruthfully(): void
    {
        $cache = $this->cache(200, '', json_encode([
            'rebuilt' => ['/'],
            'skipped' => ['/feed.xml'],
            'failed' => [['path' => '/en', 'status' => 500]],
            'unknownEvents' => [['type' => 'future']],
        ], JSON_THROW_ON_ERROR));

        $result = $cache->rebuildWithResult('https://blog.tracht-digital.de', 'tok', [new CacheEvent('post')]);
        self::assertSame(CacheResult::FAILED, $result->status);
        self::assertFalse($result->cached());
        self::assertSame(['/'], $result->rebuilt);
        self::assertCount(1, $result->failed);
        self::assertCount(1, $result->unknownEvents);
    }

    public function testA2xxWithInvalidJsonIsNotReportedAsCached(): void
    {
        $result = $this->cache(200, '', '<html>proxy error</html>')->rebuildWithResult(
            'https://blog.tracht-digital.de',
            'tok',
            [new CacheEvent('post')],
        );
        self::assertSame(CacheResult::FAILED, $result->status);
        self::assertFalse($result->cached());
    }

    public function testIgnoresAnythingThatIsNotACacheEvent(): void
    {
        // The interface takes an array; a caller passing a raw array by mistake
        // must not produce a malformed payload the site cannot parse.
        $this->cache()->rebuild('https://blog.tracht-digital.de', 'tok', [
            new CacheEvent('post', 'x'),
            ['type' => 'post'],
        ]);

        self::assertSame(
            ['events' => [['type' => 'post', 'id' => 'x']]],
            json_decode($this->sent[0]['body'], true),
        );
    }
}
