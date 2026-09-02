<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\SitemapExclusions;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * An in-memory settings store. Its own class rather than borrowing
 * `SiteKeyArrayStore` for the reason that one already gives: PHPUnit defines a
 * test-file class only while that file is loaded, so reusing it would make this
 * file pass in a full run and fail on its own.
 */
final class SitemapExclusionArrayStore implements SettingsStoreContract
{
    /** @param array<string,string> $values "namespace.key" => value */
    public function __construct(private array $values = [], private readonly bool $throws = false)
    {
    }

    public function get(string $namespace, string $key, ?string $default = null): ?string
    {
        if ($this->throws) {
            throw new \RuntimeException('no database');
        }
        return $this->values["{$namespace}.{$key}"] ?? $default;
    }

    public function getSecret(string $namespace, string $key): ?string
    {
        return null;
    }

    public function set(string $namespace, string $key, string $value, bool $secret): void
    {
        $this->values["{$namespace}.{$key}"] = $value;
    }

    public function delete(string $namespace, string $key): void
    {
        unset($this->values["{$namespace}.{$key}"]);
    }

    /** @return list<array<string,mixed>> */
    public function allMasked(string $namespace): array
    {
        return [];
    }
}

/**
 * The exclusion list is pure, and the parts that decide behaviour are exactly
 * the parts that would fail quietly in production: a pattern that was rejected
 * without saying so (the page stays indexed and the operator believes it does
 * not), a store that cannot be read (must mean "nothing excluded", never
 * "everything"), and a comparison that disagrees with the one the three sites
 * repeat in TypeScript.
 */
final class SitemapExclusionsTest extends TestCase
{
    /** @var list<string> */
    private const KNOWN = ['landingpage', 'blog', 'tools', 'auth'];

    private static function store(array $values = [], bool $throws = false): SitemapExclusionArrayStore
    {
        return new SitemapExclusionArrayStore($values, $throws);
    }

    private static function stored(array $bySite): SitemapExclusionArrayStore
    {
        return self::store([
            SitemapExclusions::NAMESPACE . '.' . SitemapExclusions::KEY => SitemapExclusions::encode($bySite),
        ]);
    }

    // --- resolve -----------------------------------------------------------

    public function testResolvesToNothingExcludedWithoutAStore(): void
    {
        $exclusions = SitemapExclusions::resolve(null);
        self::assertSame([], $exclusions->all());
        self::assertSame([], $exclusions->forSite('blog'));
        self::assertFalse($exclusions->storeAvailable);
    }

    public function testAStoreThatThrowsMeansNothingExcludedRatherThanEverything(): void
    {
        $exclusions = SitemapExclusions::resolve(self::store([], true));

        // The direction is the whole point: an empty list leaves a sitemap
        // complete, while a failure that excluded everything would empty it and
        // every consumer of this data is fail-soft too, so nothing would go red.
        self::assertSame([], $exclusions->all());
    }

    public function testReadsAStoredList(): void
    {
        $exclusions = SitemapExclusions::resolve(self::stored([
            'blog' => ['/tag/*', '/page/2'],
            'tools' => ['/tools/qr-code'],
        ]));

        self::assertSame(['/page/2', '/tag/*'], $exclusions->forSite('blog'));
        self::assertSame(['/tools/qr-code'], $exclusions->forSite('tools'));
        self::assertSame([], $exclusions->forSite('landingpage'));
    }

    public function testUnparseableStoredValueIsAnEmptyListNotAnError(): void
    {
        $raw = SitemapExclusions::NAMESPACE . '.' . SitemapExclusions::KEY;
        self::assertSame([], SitemapExclusions::resolve(self::store([$raw => 'not json']))->all());
        self::assertSame([], SitemapExclusions::resolve(self::store([$raw => '"a string"']))->all());
        self::assertSame([], SitemapExclusions::resolve(self::store([$raw => '']))->all());
    }

    public function testStoredPatternsAreRevalidatedOnRead(): void
    {
        // Written by an older shape, or edited straight in the database.
        $raw = SitemapExclusions::NAMESPACE . '.' . SitemapExclusions::KEY;
        $exclusions = SitemapExclusions::resolve(self::store([
            $raw => '{"blog":["/keep","https://evil.example/x","no-slash","/a*b"]}',
        ]));

        self::assertSame(['/keep'], $exclusions->forSite('blog'));
    }

    // --- normalize ---------------------------------------------------------

    public function testAcceptsPlainPathsAndPrefixPatterns(): void
    {
        [$accepted, $rejected] = SitemapExclusions::normalize([
            'blog' => ['/tag/*', '/aktuelles'],
        ], self::KNOWN);

        self::assertSame(['blog' => ['/aktuelles', '/tag/*']], $accepted);
        self::assertSame([], $rejected);
    }

    public function testRejectsAnUnknownSiteRatherThanInventingOne(): void
    {
        [$accepted, $rejected] = SitemapExclusions::normalize([
            'shop' => ['/x'],
        ], self::KNOWN);

        self::assertSame([], $accepted);
        self::assertCount(1, $rejected);
        self::assertSame('shop', $rejected[0]['value']);
    }

    /**
     * Every one of these is a plausible typo, and each would silently exclude
     * nothing (or the wrong thing) if it were stored as written.
     */
    public function testRejectsMalformedPatternsWithAReason(): void
    {
        $bad = [
            'https://blog.tracht-digital.de/tag/x',  // whole URL
            'tag/x',                                  // relative
            '//other.example/x',                      // protocol-relative
            '/tag/x?page=2',                          // query
            '/tag/x#top',                             // fragment
            '/tag /x',                                // whitespace
            '/a*b',                                   // star not at the end
            '/a*b*',                                  // two stars
            '/' . str_repeat('x', SitemapExclusions::MAX_LENGTH),
        ];

        [$accepted, $rejected] = SitemapExclusions::normalize(['blog' => $bad], self::KNOWN);

        self::assertSame([], $accepted);
        self::assertCount(count($bad), $rejected);
        foreach ($rejected as $entry) {
            self::assertNotSame('', $entry['reason'], 'every rejection names a reason');
        }
    }

    public function testFoldsTrailingSlashAndDeduplicates(): void
    {
        [$accepted] = SitemapExclusions::normalize([
            'landingpage' => ['/preise', '/preise/', '  /preise  '],
        ], self::KNOWN);

        self::assertSame(['landingpage' => ['/preise']], $accepted);
    }

    public function testKeepsTheTrailingStarThatMakesAPatternAPrefix(): void
    {
        [$accepted] = SitemapExclusions::normalize(['blog' => ['/tag/*']], self::KNOWN);
        self::assertSame(['blog' => ['/tag/*']], $accepted);
    }

    public function testBlankEntriesAreSkippedSilentlyButASiteWithOnlyBlanksDisappears(): void
    {
        [$accepted, $rejected] = SitemapExclusions::normalize([
            'blog' => ['', '   '],
        ], self::KNOWN);

        // A cleared form field is how a list is emptied — not an error.
        self::assertSame([], $accepted);
        self::assertSame([], $rejected);
    }

    public function testRejectsANonListForASite(): void
    {
        [$accepted, $rejected] = SitemapExclusions::normalize(['blog' => '/x'], self::KNOWN);

        self::assertSame([], $accepted);
        self::assertCount(1, $rejected);
    }

    public function testCapsThePatternCountPerSite(): void
    {
        $many = [];
        for ($i = 0; $i < SitemapExclusions::MAX_PER_SITE + 5; $i++) {
            $many[] = "/p{$i}";
        }

        [$accepted, $rejected] = SitemapExclusions::normalize(['blog' => $many], self::KNOWN);

        self::assertCount(SitemapExclusions::MAX_PER_SITE, $accepted['blog']);
        self::assertCount(5, $rejected);
    }

    public function testEncodeRoundTripsThroughResolve(): void
    {
        [$accepted] = SitemapExclusions::normalize([
            'tools' => ['/tools/qr-code', '/tools/pdf-*'],
        ], self::KNOWN);

        $back = SitemapExclusions::resolve(self::stored($accepted));
        self::assertSame($accepted['tools'], $back->forSite('tools'));
    }

    public function testEncodesAnEmptyMapAsAnObjectNotAnArray(): void
    {
        // `[]` would arrive at the panel as a JSON array and break a `for…in`
        // over site ids on the reading side.
        self::assertSame('{}', SitemapExclusions::encode([]));
    }

    // --- matches -----------------------------------------------------------

    public function testExactMatchIgnoresTrailingSlash(): void
    {
        self::assertTrue(SitemapExclusions::matches('/preise', ['/preise']));
        self::assertTrue(SitemapExclusions::matches('/preise/', ['/preise']));
        self::assertTrue(SitemapExclusions::matches('/preise', ['/preise/']));
        self::assertFalse(SitemapExclusions::matches('/preise-2026', ['/preise']));
    }

    public function testPrefixPatternMatchesBelowButNotTheBareSegment(): void
    {
        self::assertTrue(SitemapExclusions::matches('/tag/steuern', ['/tag/*']));
        self::assertTrue(SitemapExclusions::matches('/tag/a/b', ['/tag/*']));

        // `/tag` itself is a different page from the things under it, and an
        // operator hiding the tag pages has not asked to hide the index.
        self::assertFalse(SitemapExclusions::matches('/tag', ['/tag/*']));
    }

    public function testAStarDirectlyAfterASegmentIsARawPrefix(): void
    {
        self::assertTrue(SitemapExclusions::matches('/tools', ['/tools*']));
        self::assertTrue(SitemapExclusions::matches('/tools/qr', ['/tools*']));
        self::assertTrue(SitemapExclusions::matches('/toolsomething', ['/tools*']));
    }

    public function testMatchingIsCaseSensitiveBecauseUrlPathsAre(): void
    {
        self::assertFalse(SitemapExclusions::matches('/Preise', ['/preise']));
    }

    public function testEmptyPatternListMatchesNothing(): void
    {
        self::assertFalse(SitemapExclusions::matches('/preise', []));
        self::assertFalse(SitemapExclusions::matches('/', []));
    }

    public function testRootIsMatchableAndNotMatchedByAccident(): void
    {
        self::assertTrue(SitemapExclusions::matches('/', ['/']));
        self::assertFalse(SitemapExclusions::matches('/preise', ['/']));

        // A bare `*` is the deliberate "everything" pattern.
        self::assertTrue(SitemapExclusions::matches('/anything', ['*']));
    }
}
