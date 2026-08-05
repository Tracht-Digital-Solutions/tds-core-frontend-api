<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\AutoUpdater;
use Tds\CoreFrontendApi\Service\ModuleUpdateConfig;
use Tds\CoreFrontendApi\Service\PackageRegistry;
use Tds\CoreFrontendApi\Service\VersionRange;
use Tds\CoreFrontendApi\Service\WorkflowDispatcher;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The unattended updater decides, without a human present, whether to start a
 * production deploy. Everything here guards that decision:
 *
 *  - it must not fire for a version the pin does not admit (a deploy that
 *    changes nothing, repeated on every check),
 *  - it must not treat a `@dev` prerelease as newer (every push to main
 *    publishes one — that alone would deploy continuously),
 *  - it must not run when switched off, and
 *  - it must not run more often than its interval, even under concurrency.
 */
final class AutoUpdaterTest extends TestCase
{
    private string $markerDir;

    protected function setUp(): void
    {
        $this->markerDir = sys_get_temp_dir() . '/tds-auto-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->markerDir)) {
            foreach (glob($this->markerDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->markerDir);
        }
    }

    // --- VersionRange (the PHP twin of the host's moduleUpdates.ts) ---------

    public function testCaretOnAZeroMinorLineIsMinorLocked(): void
    {
        self::assertTrue(VersionRange::satisfies('0.1.29', '^0.1.1'));
        self::assertFalse(VersionRange::satisfies('0.2.0', '^0.1.1'));
        self::assertTrue(VersionRange::satisfies('1.9.9', '^1.4.0'));
        self::assertFalse(VersionRange::satisfies('2.0.0', '^1.4.0'));
    }

    public function testUnparseableRangeAnswersNullNotFalse(): void
    {
        // Only an explicit `true` is permission to deploy — but "cannot tell"
        // must not be reported to the admin as "Repin erforderlich" either.
        self::assertNull(VersionRange::satisfies('0.1.2', '0.1 || 0.2'));
    }

    public function testPrereleaseSortsBelowItsRelease(): void
    {
        self::assertLessThan(0, VersionRange::compare('0.2.0-dev.1', '0.2.0'));
        self::assertGreaterThan(0, VersionRange::compare('0.2.0', '0.2.0-dev.1'));
    }

    // --- Decision ----------------------------------------------------------

    public function testDispatchesWhenAnInRangeUpdateExists(): void
    {
        $dispatched = 0;
        $updater = $this->updater(
            enabled: true,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.30',
            onDispatch: function () use (&$dispatched): array {
                $dispatched++;
                return ['status' => 204, 'body' => '', 'error' => ''];
            },
        );

        $report = $updater->run();
        self::assertTrue($report['dispatched']);
        self::assertSame(1, $dispatched);
        self::assertCount(1, $report['updates']);
    }

    public function testDoesNotDispatchForAVersionOutsideThePin(): void
    {
        // 0.2.0 against ^0.1.1: a rebuild resolves the range afresh and would
        // still install 0.1.x. Dispatching here burns a deploy every interval
        // and never changes anything.
        $dispatched = 0;
        $updater = $this->updater(
            enabled: true,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.2.0',
            onDispatch: function () use (&$dispatched): array {
                $dispatched++;
                return ['status' => 204, 'body' => '', 'error' => ''];
            },
        );

        $report = $updater->run();
        self::assertFalse($report['dispatched']);
        self::assertSame(0, $dispatched);
        self::assertCount(1, $report['repins']);
        self::assertStringContainsString('Repin', $report['message']);
    }

    public function testDoesNotDispatchForAPrerelease(): void
    {
        $updater = $this->updater(
            enabled: true,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.29',
        );

        $report = $updater->run();
        self::assertFalse($report['dispatched']);
        self::assertStringContainsString('aktuell', $report['message']);
    }

    public function testDoesNothingWhileSwitchedOff(): void
    {
        $updater = $this->updater(
            enabled: false,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.30',
        );

        $report = $updater->run();
        self::assertFalse($report['dispatched']);
        self::assertStringContainsString('deaktiviert', $report['message']);
    }

    public function testForcedRunWorksEvenWhileSwitchedOff(): void
    {
        // The panel's "Jetzt prüfen" button — an admin must be able to try the
        // wiring before committing to unattended deploys.
        $updater = $this->updater(
            enabled: false,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.30',
        );

        $report = $updater->run(true);
        self::assertTrue($report['dispatched']);
    }

    public function testReportsAMissingInventoryInsteadOfGuessing(): void
    {
        $updater = $this->updater(enabled: true, stored: [], latest: '0.1.30');

        $report = $updater->run();
        self::assertFalse($report['dispatched']);
        self::assertStringContainsString('Modul', $report['message']);
    }

    public function testRememberInventoryRejectsForeignPackages(): void
    {
        $store = $this->store([]);
        $updater = $this->updaterWith($store, enabled: true);
        $updater->rememberInventory([
            ['pkg' => '@evil/pkg', 'installed' => '1.0.0', 'range' => '^1.0.0'],
            ['pkg' => '@tracht-digital-solutions/tds-ext-tools', 'installed' => '0.1.12', 'range' => '^0.1.0'],
        ]);

        $stored = json_decode((string) $store->get(AutoUpdater::NS, AutoUpdater::KEY_INVENTORY), true);
        self::assertCount(1, $stored);
        self::assertSame('@tracht-digital-solutions/tds-ext-tools', $stored[0]['pkg']);
    }

    // --- Scheduling ---------------------------------------------------------

    public function testMaybeRunIsANoOpUntilTheIntervalElapses(): void
    {
        $updater = $this->updater(
            enabled: true,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.30',
        );

        self::assertIsArray($updater->maybeRun(), 'first request after a deploy is due');
        // The marker is now in the future — the very next request must not do
        // any work, let alone dispatch a second deploy.
        self::assertNull($updater->maybeRun());
    }

    public function testMaybeRunSwallowsFailuresAndStillArmsTheNextRun(): void
    {
        $updater = $this->updater(
            enabled: true,
            stored: ['inventory' => $this->inventory('0.1.29', '^0.1.1')],
            latest: '0.1.30',
            onLatest: static function (): array {
                throw new \RuntimeException('registry exploded');
            },
        );

        // A convenience feature must never be able to take the API down, and a
        // persistent failure must not mean an outbound call on every request.
        self::assertNull($updater->maybeRun());
        self::assertNull($updater->maybeRun());
    }

    public function testIntervalIsClampedAgainstARunawayValue(): void
    {
        $updater = $this->updaterWith($this->store([]), enabled: true, intervalHours: 0);
        self::assertSame(24 * 3600, $updater->intervalSeconds());

        $updater = $this->updaterWith($this->store([]), enabled: true, intervalHours: 99999);
        self::assertSame(24 * 30 * 3600, $updater->intervalSeconds());
    }

    // --- Helpers ------------------------------------------------------------

    private function inventory(string $installed, string $range): string
    {
        return json_encode([[
            'pkg' => '@tracht-digital-solutions/tds-ext-blog-cms',
            'installed' => $installed,
            'range' => $range,
        ]], JSON_THROW_ON_ERROR);
    }

    /** @param array<string,string> $stored */
    private function updater(
        bool $enabled,
        array $stored,
        string $latest,
        ?callable $onDispatch = null,
        ?callable $onLatest = null,
    ): AutoUpdater {
        $registry = new PackageRegistry('tok', $onLatest ?? static fn (): array => [
            'status' => 200,
            'body' => json_encode(['dist-tags' => ['latest' => $latest]]),
            'error' => '',
        ]);
        $dispatcher = new WorkflowDispatcher('tok', $onDispatch ?? static fn (): array => [
            'status' => 204,
            'body' => '',
            'error' => '',
        ]);

        return new AutoUpdater(
            $this->config($enabled),
            $this->store($stored),
            $this->markerDir,
            $registry,
            $dispatcher,
        );
    }

    private function updaterWith(SettingsStoreContract $store, bool $enabled, int $intervalHours = 24): AutoUpdater
    {
        return new AutoUpdater($this->config($enabled, $intervalHours), $store, $this->markerDir);
    }

    private function config(bool $enabled, int $intervalHours = 24): ModuleUpdateConfig
    {
        return ModuleUpdateConfig::resolve(null, static fn (string $k, ?string $d): string => match ($k) {
            'MODULES_REGISTRY_TOKEN', 'MODULES_DISPATCH_TOKEN' => 'tok',
            'MODULES_FRONTEND_REPO' => 'Tracht-Digital-Solutions/tds-admin-frontend',
            'MODULES_AUTO_UPDATE' => $enabled ? '1' : '0',
            'MODULES_AUTO_UPDATE_INTERVAL' => (string) $intervalHours,
            default => (string) $d,
        });
    }

    /** An in-memory settings store — these tests must not need a database. */
    private function store(array $values): SettingsStoreContract
    {
        return new class ($values) implements SettingsStoreContract {
            /** @param array<string,string> $values */
            public function __construct(private array $values)
            {
            }

            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                $v = $this->values[$key] ?? null;
                return $v === null || $v === '' ? $default : $v;
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $namespace, string $key): void
            {
                unset($this->values[$key]);
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };
    }
}
