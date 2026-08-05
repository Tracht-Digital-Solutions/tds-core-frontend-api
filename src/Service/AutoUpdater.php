<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * Unattended module updates: check the registry, and when a newer version is
 * available **inside its pinned range**, start the frontend rebuild that puts
 * it into service.
 *
 * HOW IT IS SCHEDULED. There is no cron and no `proc_open` on the production
 * host — the same constraint that produced the in-process auto-migrator. So
 * this piggybacks on request traffic: every request consults a **file marker**
 * (one `file_get_contents`, no database) and does nothing until the interval
 * has elapsed. The consequence is honest and worth knowing: an API that
 * receives no traffic performs no automatic updates. {@see maybeRun()}
 *
 * WHAT IT WILL AND WILL NOT DO.
 *  - It dispatches the **frontend** rebuild only. The backend target
 *    re-assembles the bundle from every service's and extension's `main`, which
 *    would ship whatever is currently merged but unreleased — never a decision
 *    to take unattended.
 *  - It acts only on `update` (newer AND in range). A newer version outside the
 *    pin needs a repin commit in the product repo; dispatching for one would
 *    fire a deploy on every check and change nothing.
 *  - It rate-limits itself to one dispatch per interval, and records what it
 *    did so the Module page can show it.
 *
 * WHERE THE INVENTORY COMES FROM. The pinned ranges live in the product's
 * `package.json`, which this API never sees — the panel posts its build-time
 * inventory to `/admin/modules/check` and it is stored from there. So automatic
 * updates begin working once an admin has opened the Module page once. That
 * bootstrap is deliberate: the alternative is this service guessing at another
 * repo's pins.
 */
final class AutoUpdater
{
    public const NS = ModuleUpdateConfig::NAMESPACE;

    /** State keys (namespace `modules`) — not form fields, see ModuleUpdateConfig. */
    public const KEY_INVENTORY = 'inventory';
    public const KEY_LAST_RUN = 'auto_last_run';
    public const KEY_LAST_RESULT = 'auto_last_result';
    public const KEY_LAST_DISPATCH = 'auto_last_dispatch';

    /** Clamp: a runaway interval setting must not mean "every request". */
    private const MIN_INTERVAL_HOURS = 1;
    private const MAX_INTERVAL_HOURS = 24 * 30;

    /** Provisional lock while a run is in flight, so concurrent requests do not stampede. */
    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly ModuleUpdateConfig $config,
        private readonly ?SettingsStoreContract $store,
        private readonly string $markerDir,
        private readonly ?PackageRegistry $registry = null,
        private readonly ?WorkflowDispatcher $dispatcher = null,
    ) {
    }

    /**
     * Persist the panel's build-time inventory so an unattended run has
     * something to check. Silently ignored without a store.
     *
     * @param list<array{pkg:string,installed:string,range:string}> $entries
     */
    public function rememberInventory(array $entries): void
    {
        $clean = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pkg = trim((string) ($entry['pkg'] ?? ''));
            if ($pkg === '' || !PackageRegistry::isAllowed($pkg)) {
                continue;
            }
            $clean[] = [
                'pkg' => $pkg,
                'installed' => trim((string) ($entry['installed'] ?? '')),
                'range' => trim((string) ($entry['range'] ?? '')),
            ];
        }
        if ($clean === []) {
            return;
        }
        try {
            $this->store?->set(self::NS, self::KEY_INVENTORY, json_encode($clean, JSON_THROW_ON_ERROR), false);
        } catch (\Throwable) {
            /* no DB — automatic updates simply stay unavailable */
        }
    }

    /**
     * The cheap per-request entry point. Returns null when nothing was due (the
     * overwhelmingly common case) and never throws — an admin convenience must
     * not be able to take the API down.
     *
     * @return array<string,mixed>|null
     */
    public function maybeRun(): ?array
    {
        try {
            if (!$this->isDue()) {
                return null;
            }
            // Claim the slot BEFORE any slow work: a second request arriving
            // mid-run would otherwise repeat the whole check and could dispatch
            // the same deploy twice.
            $this->writeMarker(time() + self::LOCK_SECONDS);

            $report = $this->run();
            $this->writeMarker(time() + $this->intervalSeconds());
            return $report;
        } catch (\Throwable) {
            // Push the next attempt out so a persistent failure cannot turn into
            // an outbound request on every hit.
            try {
                $this->writeMarker(time() + $this->intervalSeconds());
            } catch (\Throwable) {
                /* nothing left to do */
            }
            return null;
        }
    }

    /**
     * Run the check now, regardless of schedule (the panel's "Jetzt prüfen"
     * button). Always returns a report describing what happened and why.
     *
     * @return array<string,mixed>
     */
    public function run(bool $force = false): array
    {
        $report = [
            'enabled' => $this->isEnabled(),
            'ran_at' => date('c'),
            'checked' => 0,
            'updates' => [],
            'repins' => [],
            'dispatched' => false,
            'message' => '',
        ];

        if (!$force && !$report['enabled']) {
            $report['message'] = 'Automatische Updates sind deaktiviert.';
            return $this->record($report);
        }

        $inventory = $this->inventory();
        if ($inventory === []) {
            $report['message'] = 'Keine Modulübersicht gespeichert — die Modul-Seite einmal öffnen.';
            return $this->record($report);
        }

        $registry = $this->registry ?? new PackageRegistry($this->config->registryToken);
        if (!$registry->isConfigured()) {
            $report['message'] = 'Kein Registry-Token hinterlegt.';
            return $this->record($report);
        }

        $latest = $registry->latestMany(array_column($inventory, 'pkg'));
        $report['checked'] = count($latest);

        foreach ($inventory as $entry) {
            $published = $latest[$entry['pkg']] ?? null;
            if (!is_string($published) || $published === '' || $entry['installed'] === '') {
                continue;
            }
            if (VersionRange::compare($published, $entry['installed']) <= 0) {
                continue;
            }
            // Only an explicit `true` is permission to deploy — an unparseable
            // range answers null and is left to a human.
            if (VersionRange::satisfies($published, $entry['range']) === true) {
                $report['updates'][] = ['pkg' => $entry['pkg'], 'from' => $entry['installed'], 'to' => $published];
            } else {
                $report['repins'][] = ['pkg' => $entry['pkg'], 'from' => $entry['installed'], 'to' => $published];
            }
        }

        if ($report['updates'] === []) {
            $report['message'] = $report['repins'] === []
                ? 'Alle Module sind aktuell.'
                : sprintf('Keine Updates innerhalb der gepinnten Linien (%d Modul(e) benötigen einen Repin).', count($report['repins']));
            return $this->record($report);
        }

        $target = $this->config->target('frontend');
        if ($target === null) {
            $report['message'] = sprintf(
                '%d Update(s) verfügbar, aber kein Frontend-Deploy konfiguriert.',
                count($report['updates']),
            );
            return $this->record($report);
        }

        $dispatcher = $this->dispatcher ?? new WorkflowDispatcher($this->config->dispatchToken);
        $result = $dispatcher->dispatch($target['repo'], $target['workflow'], $this->config->ref);
        $report['dispatched'] = $result['ok'];
        $report['message'] = $result['ok']
            ? sprintf('%d Update(s) gefunden — Rebuild gestartet.', count($report['updates']))
            : sprintf('%d Update(s) gefunden, Deploy fehlgeschlagen: %s', count($report['updates']), $result['message']);

        if ($result['ok']) {
            $this->write(self::KEY_LAST_DISPATCH, date('c'));
        }
        return $this->record($report);
    }

    /** The panel's view of the automation, for the check response. @return array<string,mixed> */
    public function state(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'interval_hours' => (int) round($this->intervalSeconds() / 3600),
            'last_run' => $this->read(self::KEY_LAST_RUN),
            'last_result' => $this->read(self::KEY_LAST_RESULT),
            'last_dispatch' => $this->read(self::KEY_LAST_DISPATCH),
            'next_run' => $this->nextRunAt(),
            'inventory_known' => $this->inventory() !== [],
        ];
    }

    public function isEnabled(): bool
    {
        return $this->config->autoUpdate;
    }

    /** Configured interval, clamped so a stray value cannot mean "every request". */
    public function intervalSeconds(): int
    {
        $hours = $this->config->autoIntervalHours;
        if ($hours < self::MIN_INTERVAL_HOURS) {
            $hours = 24;
        }
        return min($hours, self::MAX_INTERVAL_HOURS) * 3600;
    }

    // --- Scheduling marker --------------------------------------------------

    private function markerFile(): string
    {
        return $this->markerDir . '/next-run';
    }

    private function isDue(): bool
    {
        $raw = @file_get_contents($this->markerFile());
        if ($raw === false) {
            // First request after a deploy: due, but the run itself still checks
            // whether the feature is enabled.
            return true;
        }
        return time() >= (int) trim($raw);
    }

    private function nextRunAt(): ?string
    {
        $raw = @file_get_contents($this->markerFile());
        if ($raw === false) {
            return null;
        }
        return date('c', (int) trim($raw));
    }

    private function writeMarker(int $timestamp): void
    {
        if (!is_dir($this->markerDir)) {
            @mkdir($this->markerDir, 0775, true);
        }
        @file_put_contents($this->markerFile(), (string) $timestamp, LOCK_EX);
    }

    // --- Settings-backed state ---------------------------------------------

    /** @return list<array{pkg:string,installed:string,range:string}> */
    private function inventory(): array
    {
        $raw = $this->read(self::KEY_INVENTORY);
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function record(array $report): array
    {
        $this->write(self::KEY_LAST_RUN, (string) $report['ran_at']);
        $this->write(self::KEY_LAST_RESULT, (string) $report['message']);
        return $report;
    }

    private function read(string $key): ?string
    {
        try {
            return $this->store?->get(self::NS, $key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function write(string $key, string $value): void
    {
        try {
            $this->store?->set(self::NS, $key, $value, false);
        } catch (\Throwable) {
            /* no DB — the run still happened, it just is not remembered */
        }
    }
}
