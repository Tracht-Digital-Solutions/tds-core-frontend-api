<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The site-key policy: what happens to a protected route reached without a key,
 * and which sites exist to hold one.
 *
 * Read **DB-first with an env fallback**, the platform's normal pattern
 * ({@see SettingsStore} namespace `sites`, edited under Einstellungen →
 * *Site-Verbindungen*). Note this is NOT the CORS inversion: there, the stored
 * layer only ever adds to the coded baseline because an admin could otherwise
 * remove the origin their own panel runs on and lock themselves out. Nothing
 * here can lock anybody out — the panel's own routes are never site-key
 * protected — so the usual precedence applies.
 *
 * ### Why enforcement is three-valued
 *
 * `off` → `enforce` in one step breaks whichever site you forgot, in
 * production, with no prior signal, and the breakage is *invisible*: every
 * build-time content fetch is fail-soft, so a rejected build renders its baked
 * fallbacks and reports success. `warn` serves and counts, so the panel can say
 * "37 keyless reads since the 12th, last from tools.tracht-digital.de" while
 * keys are rolled out. Same shape and same reason as support-tickets'
 * `ingest_mode`.
 *
 * Never throws: without a database it resolves to the env value, or `off`.
 */
final class SiteKeyPolicy
{
    public const NAMESPACE = 'sites';
    public const KEY_ENFORCEMENT = 'enforcement';
    public const KEY_CUSTOM_SITES = 'custom_sites';
    public const KEY_UNKEYED = 'unkeyed_hits';

    public const MODES = ['off', 'warn', 'enforce'];

    /**
     * The sites the platform knows about — the server-side twin of
     * `tds-shared-pkg/src/install/profiles.ts`, which until now was the only
     * place the four public origins were enumerated at all, and lived compiled
     * into a frontend bundle where the API could never see it.
     *
     * `auth` is included even though it reads nothing from this API: the wizard
     * runs there too, and a site that cannot register is a site whose operator
     * assumes the step failed.
     *
     * @var list<array{id: string, label: string, origins: list<string>}>
     */
    public const KNOWN = [
        ['id' => 'landingpage', 'label' => 'Landingpage', 'origins' => [
            'https://tracht-digital.de',
            'https://www.tracht-digital.de',
        ]],
        ['id' => 'blog', 'label' => 'Blog', 'origins' => ['https://blog.tracht-digital.de']],
        ['id' => 'tools', 'label' => 'Tools', 'origins' => ['https://tools.tracht-digital.de']],
        ['id' => 'auth', 'label' => 'Login', 'origins' => ['https://auth.tracht-digital.de']],
    ];

    private function __construct(
        /** @var 'off'|'warn'|'enforce' */
        public readonly string $enforcement,
        /** @var list<array{id: string, label: string, origins: list<string>}> */
        public readonly array $customSites,
        /** Whether the stored layer could be read at all (`false` = no DB yet). */
        public readonly bool $storeAvailable,
    ) {
    }

    /** @param callable(string,?string):string $env The Bootstrap env reader. */
    public static function resolve(?SettingsStoreContract $store, callable $env): self
    {
        $stored = null;
        $customRaw = null;
        $available = false;
        try {
            $stored = $store?->get(self::NAMESPACE, self::KEY_ENFORCEMENT);
            $customRaw = $store?->get(self::NAMESPACE, self::KEY_CUSTOM_SITES);
            $available = $store !== null;
        } catch (\Throwable) {
            // No DB yet — env-only, which is the pre-settings behaviour.
        }

        $mode = self::normalizeMode((string) ($stored ?? ''));
        if ($mode === null) {
            $mode = self::normalizeMode((string) $env('SITE_KEY_ENFORCEMENT', '')) ?? 'off';
        }

        return new self($mode, self::decodeSites((string) ($customRaw ?? '')), $available);
    }

    /** @return 'off'|'warn'|'enforce'|null null when the input names no mode. */
    public static function normalizeMode(string $value): ?string
    {
        $value = strtolower(trim($value));
        /** @var 'off'|'warn'|'enforce'|null */
        return in_array($value, self::MODES, true) ? $value : null;
    }

    /**
     * Known sites plus the custom ones, in that order.
     *
     * A custom entry may not reuse a known id — the known origins would then be
     * shadowed by whatever somebody typed, and the resulting CORS advice would
     * be wrong about the site the panel itself is describing.
     *
     * @return list<array{id: string, label: string, origins: list<string>, known: bool}>
     */
    public function sites(): array
    {
        $out = [];
        $seen = [];
        foreach (self::KNOWN as $site) {
            $seen[$site['id']] = true;
            $out[] = $site + ['known' => true];
        }
        foreach ($this->customSites as $site) {
            if (isset($seen[$site['id']])) {
                continue;
            }
            $seen[$site['id']] = true;
            $out[] = $site + ['known' => false];
        }
        return $out;
    }

    /**
     * Parse the submitted custom-site list, reporting what it could not use.
     *
     * Rejects are handed back rather than dropped, for the reason the CORS form
     * already learned: an entry that was silently discarded looks saved, and the
     * key issued for it then never matches anything.
     *
     * @param  list<mixed> $submitted
     * @return array{0: list<array{id: string, label: string, origins: list<string>}>, 1: list<array{value: string, reason: string}>}
     */
    public static function normalizeSites(array $submitted): array
    {
        $known = array_column(self::KNOWN, 'id');
        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($submitted as $entry) {
            if (!is_array($entry)) {
                $rejected[] = ['value' => (string) (is_scalar($entry) ? $entry : ''), 'reason' => 'Kein Objekt.'];
                continue;
            }
            $rawId = trim((string) ($entry['id'] ?? ''));
            $id = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $rawId) ?? '');
            $id = trim($id, '-');
            if ($id === '') {
                $rejected[] = ['value' => $rawId, 'reason' => 'Kennung fehlt oder enthält nur Sonderzeichen.'];
                continue;
            }
            if (in_array($id, $known, true)) {
                $rejected[] = ['value' => $rawId, 'reason' => 'Kennung ist bereits fest vergeben.'];
                continue;
            }
            if (isset($seen[$id])) {
                $rejected[] = ['value' => $rawId, 'reason' => 'Kennung doppelt.'];
                continue;
            }

            // Deliberately NOT CorsConfig::normalizeList(): that one drops an
            // origin already in the CORS baseline as a duplicate, which is right
            // for the allow-list and wrong here — a site DECLARES the origin it
            // runs on, and dropping it because it happens to be allowed already
            // would leave the entry with no origin and its CORS advice blank.
            $origins = [];
            $raw = $entry['origins'] ?? '';
            $list = is_array($raw) ? $raw : CorsConfig::split((string) $raw);
            foreach ($list as $value) {
                [$origin, $reason] = CorsConfig::normalizeOrigin((string) $value);
                if ($origin === null) {
                    if (trim((string) $value) !== '') {
                        $rejected[] = ['value' => trim((string) $value), 'reason' => (string) $reason];
                    }
                    continue;
                }
                if (!in_array($origin, $origins, true)) {
                    $origins[] = $origin;
                }
            }

            $seen[$id] = true;
            $accepted[] = [
                'id' => $id,
                'label' => trim((string) ($entry['label'] ?? '')) ?: $id,
                'origins' => $origins,
            ];
        }

        return [$accepted, $rejected];
    }

    /** @param list<array{id: string, label: string, origins: list<string>}> $sites */
    public static function encodeSites(array $sites): string
    {
        return json_encode($sites, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array{id: string, label: string, origins: list<string>}> */
    private static function decodeSites(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        [$accepted] = self::normalizeSites(array_values($decoded));
        return $accepted;
    }
}
