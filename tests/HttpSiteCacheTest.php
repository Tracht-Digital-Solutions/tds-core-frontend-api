<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\HttpSiteCache;
use Tds\Frontend\Contract\CacheEvent;

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

    private function cache(int $status = 200, string $error = ''): HttpSiteCache
    {
        return new HttpSiteCache(function (string $url, array $headers, string $body) use ($status, $error): array {
            $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            return ['status' => $status, 'error' => $error];
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
