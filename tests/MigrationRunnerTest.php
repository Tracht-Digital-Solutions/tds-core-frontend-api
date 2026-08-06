<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Support\MigrationRunner;

/**
 * DB-free coverage of the auto-migrator's control flow — the marker/single-flight
 * bookkeeping and the collision guard — with an injected fake migrate callable.
 * (The real Phinx path is exercised by deploying against MySQL.)
 */
final class MigrationRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/tds-mrt-' . uniqid('', true);
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->tmp);
    }

    /**
     * A migration dir with one file per class name.
     *
     * File names are derived the way Phinx derives them back — `CreateShared`
     * becomes `..._create_shared.php` — because the runner now enforces that
     * round-trip. The old helper wrote `strtolower($class)`, which produces
     * `createshared` and maps back to `Createshared`: every fixture would have
     * tripped the new file-name guard, and the suite would have been testing
     * the guard instead of the behaviour under it.
     *
     * @param string[] $classes
     * @param int|null $startVersion Override to force a version collision.
     */
    private function migDir(string $name, array $classes, ?int $startVersion = null): string
    {
        $dir = $this->tmp . '/' . $name;
        mkdir($dir . '/db/migrations', 0775, true);
        $ts = $startVersion ?? 20260719000000;
        foreach ($classes as $class) {
            file_put_contents(
                $dir . '/db/migrations/' . (++$ts) . '_' . self::snake($class) . '.php',
                "<?php\nfinal class {$class} {}\n",
            );
        }
        return $dir . '/db/migrations';
    }

    /** `CreateShared` → `create_shared` (the inverse of Phinx's derivation). */
    private static function snake(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }

    /** Write one migration file verbatim, for the malformed-input cases. */
    private function rawMigration(string $name, string $fileName, string $body): string
    {
        $dir = $this->tmp . '/' . $name . '/db/migrations';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/' . $fileName, $body);
        return $dir;
    }

    private function stateDir(): string
    {
        return $this->tmp . '/state';
    }

    public function testAppliesPendingAndWritesMarker(): void
    {
        $calls = 0;
        $runner = new MigrationRunner(
            [$this->migDir('a', ['CreateA'])],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(1, $calls);
        $markers = glob($this->stateDir() . '/.migrated-*');
        self::assertNotEmpty($markers, 'a marker should be written on success');
    }

    public function testMarkerShortCircuitsSecondRun(): void
    {
        $calls = 0;
        $migrate = function () use (&$calls): array {
            $calls++;
            return [true, 'ok'];
        };
        $paths = [$this->migDir('a', ['CreateA'])];
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();

        self::assertSame(1, $calls, 'the second run must short-circuit on the marker');
    }

    public function testEmptyPathsIsNoop(): void
    {
        $calls = 0;
        (new MigrationRunner([], ['name' => 'x'], $this->stateDir(), null, function () use (&$calls): array {
            $calls++;
            return [true, 'ok'];
        }))->ensureMigrated();

        self::assertSame(0, $calls);
    }

    public function testDuplicateClassNameAbortsWithoutMigrating(): void
    {
        $calls = 0;
        // Distinct version bands on purpose: with the default the two dirs also
        // collide on the version prefix, that guard fires first, and this test
        // passes while asserting something it never exercised.
        $runner = new MigrationRunner(
            [
                $this->migDir('a', ['CreateShared'], 20260725000000),
                $this->migDir('b', ['CreateShared'], 20260726000000),
            ],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(0, $calls, 'a class-name collision must abort before Phinx includes the files');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    public function testFileNameNotMatchingItsClassAbortsWithoutMigrating(): void
    {
        // THE defect this guard was extended for. Phinx derives the expected
        // class from the file name and throws `Could not find class …` while the
        // set is merely SCANNED — which aborts the run for every extension, so
        // nothing migrates at all and every route 500s on a fresh database.
        // tds-ext-live-chat-cta-pkg (5 files) and tds-ext-tools-pkg (2) shipped
        // exactly this and had never applied a single migration.
        $calls = 0;
        $runner = new MigrationRunner(
            [$this->rawMigration('a', '20260719000001_create_faq.php', "<?php\nfinal class CreateLiveChatCtaFaq {}\n")],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(0, $calls, 'a file-name/class mismatch must abort before Phinx scans the set');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    public function testDuplicateVersionPrefixAbortsWithoutMigrating(): void
    {
        // Distinct class names, same numeric prefix. Every extension shares ONE
        // phinxlog, so Phinx aborts on the duplicate version even though nothing
        // would fatally redeclare.
        $calls = 0;
        $runner = new MigrationRunner(
            [
                $this->migDir('a', ['CreateAlpha'], 20260801000000),
                $this->migDir('b', ['CreateBeta'], 20260801000000),
            ],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(0, $calls, 'a duplicate version prefix must abort — one shared phinxlog');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    public function testAWellFormedSetStillMigrates(): void
    {
        // The counterweight: three extensions, distinct classes, distinct
        // versions, file names that map. A guard that rejects everything would
        // pass all the tests above and take the whole platform down.
        $calls = 0;
        $runner = new MigrationRunner(
            [
                $this->migDir('a', ['CreateAlpha', 'AlphaAddColumn'], 20260725000000),
                $this->migDir('b', ['CreateBeta'], 20260726000000),
                $this->migDir('c', ['CreateGamma'], 20260727000000),
            ],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(1, $calls);
        self::assertNotEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    public function testFailureIsNotMarkedAndRetries(): void
    {
        $calls = 0;
        $migrate = function () use (&$calls): array {
            $calls++;
            return [false, 'boom'];
        };
        $paths = [$this->migDir('a', ['CreateA'])];
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();

        self::assertSame(2, $calls, 'a failed migrate must not latch a marker — it retries');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    private static function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach ((array) scandir($path) as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::rmrf($path . '/' . $e);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
}
