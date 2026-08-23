<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\CorsConfig;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/** An in-memory settings store — the class under test only ever reads one key. */
final class ArrayStore implements SettingsStoreContract
{
    /** @param array<string,string> $values namespace.key => value */
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
 * The CORS allow-list is editable in the panel now. Two properties matter more
 * than the plumbing, and both are here:
 *
 *   - the three layers UNION rather than override, so nothing an admin saves in
 *     the browser can remove the origin the browser is running on;
 *   - a submitted origin is normalised to the exact form a browser sends, or
 *     rejected with a reason — because the comparison is an exact string match
 *     and a near miss fails silently and forever.
 */
final class CorsConfigTest extends TestCase
{
    /** @param array<string,string> $env */
    private static function env(array $env): callable
    {
        return static fn (string $key, ?string $default = null): string => $env[$key] ?? (string) $default;
    }

    public function testBaselineSurvivesAnEmptyEnvAndAnEmptyStore(): void
    {
        $config = CorsConfig::resolve(new ArrayStore(), self::env([]));

        self::assertSame(CorsConfig::BASELINE, $config->origins());
    }

    public function testEnvAndStoredEntriesBothAdd(): void
    {
        $config = CorsConfig::resolve(
            new ArrayStore(['cors.allowed_origins' => "https://kunde.example\nhttps://staging.example"]),
            self::env(['CORS_ALLOWED_ORIGINS' => 'http://localhost:4321']),
        );

        $origins = $config->origins();
        self::assertContains('http://localhost:4321', $origins);
        self::assertContains('https://kunde.example', $origins);
        self::assertContains('https://staging.example', $origins);
        foreach (CorsConfig::BASELINE as $baseline) {
            self::assertContains($baseline, $origins);
        }
    }

    public function testAStoredListCannotRemoveABaselineOrigin(): void
    {
        // The lock-out case, and the reason this namespace unions instead of
        // overriding: the panel runs on management.tracht-digital.de, so an
        // admin who "cleaned up" the list would have no surface left to undo it
        // from — the API would stop answering the very page that saves.
        $config = CorsConfig::resolve(
            new ArrayStore(['cors.allowed_origins' => 'https://kunde.example']),
            self::env([]),
        );

        self::assertContains('https://management.tracht-digital.de', $config->origins());
        self::assertContains('https://app.tracht-digital.de', $config->origins());
    }

    public function testADeadDatabaseFallsBackToBaselinePlusEnv(): void
    {
        $config = CorsConfig::resolve(
            new ArrayStore([], throws: true),
            self::env(['CORS_ALLOWED_ORIGINS' => 'http://localhost:4321']),
        );

        self::assertContains('http://localhost:4321', $config->origins());
        self::assertContains('https://blog.tracht-digital.de', $config->origins());
    }

    public function testStatusNamesTheLayerEachOriginCameFrom(): void
    {
        $config = CorsConfig::resolve(
            new ArrayStore(['cors.allowed_origins' => 'https://kunde.example']),
            self::env(['CORS_ALLOWED_ORIGINS' => 'http://localhost:4321']),
        );

        $bySource = [];
        foreach ($config->status()['origins'] as $row) {
            $bySource[$row['origin']] = $row['source'];
        }

        self::assertSame('baseline', $bySource['https://tracht-digital.de']);
        self::assertSame('env', $bySource['http://localhost:4321']);
        self::assertSame('db', $bySource['https://kunde.example']);
    }

    public function testAnOriginAlreadyInTheBaselineIsNotListedTwice(): void
    {
        $config = CorsConfig::resolve(
            new ArrayStore(['cors.allowed_origins' => 'https://blog.tracht-digital.de']),
            self::env(['CORS_ALLOWED_ORIGINS' => 'https://blog.tracht-digital.de']),
        );

        $count = count(array_filter(
            $config->status()['origins'],
            static fn (array $row): bool => $row['origin'] === 'https://blog.tracht-digital.de',
        ));
        self::assertSame(1, $count);
        self::assertSame(CorsConfig::BASELINE, $config->origins());
    }

    /** @dataProvider normalisations */
    public function testNormalizeOrigin(string $input, ?string $expected): void
    {
        [$origin] = CorsConfig::normalizeOrigin($input);
        self::assertSame($expected, $origin);
    }

    /** @return array<string, array{string, ?string}> */
    public static function normalisations(): array
    {
        return [
            // THE paste error. A trailing slash makes the exact-match compare
            // fail forever, and nothing anywhere reports it.
            'trailing slash' => ['https://kunde.example/', 'https://kunde.example'],
            'surrounding space' => ['  https://kunde.example  ', 'https://kunde.example'],
            'uppercase host' => ['HTTPS://Kunde.Example', 'https://kunde.example'],
            'explicit port' => ['http://localhost:4321', 'http://localhost:4321'],
            'plain' => ['https://kunde.example', 'https://kunde.example'],
            // Refused rather than stripped: an admin who pasted a deep link
            // must learn that the compared value is not what they pasted.
            'a path is not an origin' => ['https://kunde.example/app', null],
            'no scheme' => ['kunde.example', null],
            'wrong scheme' => ['ftp://kunde.example', null],
            'credentials' => ['https://u:p@kunde.example', null],
            'empty' => ['', null],
        ];
    }

    public function testTheWildcardIsRejectedWithItsOwnReason(): void
    {
        // Reaching for `*` is the natural move for "allow everything", and it
        // would break every request instead: this list is served with
        // Allow-Credentials, and the spec forbids the wildcard there.
        [$origin, $reason] = CorsConfig::normalizeOrigin('*');

        self::assertNull($origin);
        self::assertStringContainsString('Allow-Credentials', (string) $reason);
    }

    public function testNormalizeListDropsBaselineAndDuplicatesAndReportsRejects(): void
    {
        [$accepted, $rejected] = CorsConfig::normalizeList([
            'https://kunde.example/',
            'https://kunde.example',
            'https://blog.tracht-digital.de',
            'nonsense',
            '   ',
        ]);

        self::assertSame(['https://kunde.example'], $accepted);
        self::assertCount(1, $rejected);
        self::assertSame('nonsense', $rejected[0]['value']);
        self::assertNotSame('', $rejected[0]['reason']);
    }

    public function testSplitAcceptsCommasAndNewlines(): void
    {
        // The `.env` is comma-separated and the panel textarea is one per line,
        // and an operator will paste one into the other.
        self::assertSame(
            ['https://a.example', 'https://b.example', 'https://c.example'],
            CorsConfig::split("https://a.example, https://b.example\nhttps://c.example\n"),
        );
    }
}
