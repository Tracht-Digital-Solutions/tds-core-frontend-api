<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\SiteKeyPolicy;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * An in-memory settings store. Deliberately its own class rather than reusing
 * CorsConfigTest's: PHPUnit only defines that one while that file is loaded, so
 * borrowing it makes this file pass in a full run and fail on its own — the
 * kind of coupling that is discovered while debugging something else.
 */
final class SiteKeyArrayStore implements SettingsStoreContract
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
 * The site-key policy is pure, so the parts that decide behaviour are testable
 * without a database — and they are the parts that fail silently in production:
 * an unrecognised enforcement value, a custom site whose origin was dropped, a
 * stored blob that no longer parses.
 */
final class SiteKeyPolicyTest extends TestCase
{
    private static function env(array $vars): callable
    {
        return static fn (string $key, ?string $default = null): string => $vars[$key] ?? (string) $default;
    }

    public function testDefaultsToOffWithNoStoreAndNoEnv(): void
    {
        $policy = SiteKeyPolicy::resolve(null, self::env([]));
        self::assertSame('off', $policy->enforcement);
        self::assertFalse($policy->storeAvailable);
    }

    public function testFallsBackToTheEnvValue(): void
    {
        $policy = SiteKeyPolicy::resolve(null, self::env(['SITE_KEY_ENFORCEMENT' => 'enforce']));
        self::assertSame('enforce', $policy->enforcement);
    }

    public function testTheStoredValueOutranksTheEnv(): void
    {
        // The normal precedence — unlike CORS, where the layers are unioned so
        // an admin cannot lock the panel out. Nothing here can lock anybody out:
        // the panel's own routes are never site-key protected.
        $store = new SiteKeyArrayStore(['sites.enforcement' => 'warn']);
        $policy = SiteKeyPolicy::resolve($store, self::env(['SITE_KEY_ENFORCEMENT' => 'enforce']));
        self::assertSame('warn', $policy->enforcement);
    }

    public function testAnUnrecognisedModeFallsThroughRatherThanBeingHonoured(): void
    {
        // A typo'd stored mode must not silently become "something other than
        // off" — and must not shadow a deliberate env setting either.
        $store = new SiteKeyArrayStore(['sites.enforcement' => 'ENFOCE']);
        $policy = SiteKeyPolicy::resolve($store, self::env(['SITE_KEY_ENFORCEMENT' => 'warn']));
        self::assertSame('warn', $policy->enforcement);
    }

    public function testModeComparisonIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('enforce', SiteKeyPolicy::normalizeMode(' Enforce '));
        self::assertNull(SiteKeyPolicy::normalizeMode('strict'));
    }

    public function testAThrowingStoreDegradesToTheEnvLayer(): void
    {
        $policy = SiteKeyPolicy::resolve(new SiteKeyArrayStore([], true), self::env(['SITE_KEY_ENFORCEMENT' => 'warn']));
        self::assertSame('warn', $policy->enforcement);
    }

    public function testKnownSitesAreAlwaysPresent(): void
    {
        $ids = array_column(SiteKeyPolicy::resolve(null, self::env([]))->sites(), 'id');
        self::assertSame(['landingpage', 'blog', 'tools', 'auth'], $ids);
    }

    public function testCustomSitesFollowTheKnownOnes(): void
    {
        $store = new SiteKeyArrayStore(['sites.custom_sites' => json_encode([
            ['id' => 'kunde-a', 'label' => 'Kunde A', 'origins' => ['https://kunde-a.example']],
        ])]);
        $sites = SiteKeyPolicy::resolve($store, self::env([]))->sites();

        self::assertCount(5, $sites);
        self::assertSame('kunde-a', $sites[4]['id']);
        self::assertFalse($sites[4]['known']);
        self::assertTrue($sites[0]['known']);
    }

    public function testACustomSiteMayNotShadowAKnownId(): void
    {
        // Otherwise the coded origins of the real site are replaced by whatever
        // somebody typed, and the CORS advice the panel gives about that site
        // becomes confidently wrong.
        [$accepted, $rejected] = SiteKeyPolicy::normalizeSites([
            ['id' => 'blog', 'label' => 'Mein Blog', 'origins' => ['https://x.example']],
        ]);
        self::assertSame([], $accepted);
        self::assertStringContainsString('fest vergeben', $rejected[0]['reason']);
    }

    public function testKeepsAnOriginThatIsAlreadyInTheCorsBaseline(): void
    {
        // CorsConfig::normalizeList() drops a baseline origin as a duplicate,
        // which is right for the allow-list and wrong here: a site DECLARES the
        // origin it runs on, and dropping it would leave the entry origin-less.
        [$accepted] = SiteKeyPolicy::normalizeSites([
            ['id' => 'spiegel', 'origins' => ['https://blog.tracht-digital.de']],
        ]);
        self::assertSame(['https://blog.tracht-digital.de'], $accepted[0]['origins']);
    }

    public function testReportsAnUnusableOriginInsteadOfDroppingIt(): void
    {
        // The single most common paste error. Silently discarded it would look
        // saved, and the key issued for that site would match nothing.
        [$accepted, $rejected] = SiteKeyPolicy::normalizeSites([
            ['id' => 'kunde-b', 'origins' => ['https://kunde-b.example/pfad', 'https://ok.example']],
        ]);
        self::assertSame(['https://ok.example'], $accepted[0]['origins']);
        self::assertCount(1, $rejected);
        self::assertSame('https://kunde-b.example/pfad', $rejected[0]['value']);
    }

    public function testRejectsAnEmptyOrPunctuationOnlyId(): void
    {
        [$accepted, $rejected] = SiteKeyPolicy::normalizeSites([['id' => '///', 'origins' => []]]);
        self::assertSame([], $accepted);
        self::assertStringContainsString('Kennung', $rejected[0]['reason']);
    }

    public function testRejectsADuplicateCustomId(): void
    {
        [$accepted, $rejected] = SiteKeyPolicy::normalizeSites([
            ['id' => 'kunde', 'origins' => []],
            ['id' => 'kunde', 'origins' => []],
        ]);
        self::assertCount(1, $accepted);
        self::assertStringContainsString('doppelt', $rejected[0]['reason']);
    }

    public function testALabelDefaultsToTheId(): void
    {
        [$accepted] = SiteKeyPolicy::normalizeSites([['id' => 'kunde-c', 'origins' => []]]);
        self::assertSame('kunde-c', $accepted[0]['label']);
    }

    public function testAcceptsOriginsAsANewlineSeparatedBlob(): void
    {
        // The panel edits them in a textarea; the stored form is a list. An
        // operator will paste one into the other.
        [$accepted] = SiteKeyPolicy::normalizeSites([
            ['id' => 'kunde-d', 'origins' => "https://a.example\nhttps://b.example"],
        ]);
        self::assertSame(['https://a.example', 'https://b.example'], $accepted[0]['origins']);
    }

    public function testAnUnparseableStoredBlobIsIgnoredRatherThanFatal(): void
    {
        $store = new SiteKeyArrayStore(['sites.custom_sites' => '{not json']);
        $policy = SiteKeyPolicy::resolve($store, self::env([]));
        self::assertSame([], $policy->customSites);
        self::assertCount(4, $policy->sites());
    }

    public function testRoundTripsThroughTheStoredEncoding(): void
    {
        [$accepted] = SiteKeyPolicy::normalizeSites([
            ['id' => 'kunde-e', 'label' => 'Kunde E', 'origins' => ['https://kunde-e.example']],
        ]);
        $store = new SiteKeyArrayStore(['sites.custom_sites' => SiteKeyPolicy::encodeSites($accepted)]);
        self::assertSame($accepted, SiteKeyPolicy::resolve($store, self::env([]))->customSites);
    }
}
