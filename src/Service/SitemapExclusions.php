<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * Which paths each public site must keep out of its sitemap.
 *
 * The three public sites build their own sitemaps — the URL list comes from
 * the corpus (blog), the catalog (tools) or the committed route inventory
 * (landingpage), and none of that is editable from here. What IS editable is
 * the subtraction: a list of paths per site that the site removes from its
 * sitemap and marks `noindex` when it renders them.
 *
 * ### Why a path list and not a flag per row
 *
 * Because most of what an operator wants to hide has no row to hang a flag on.
 * `/preise` is a committed Astro page, `/tag/steuern` and `/page/3` are derived
 * from other posts' fields, and the landingpage's service pages are code-owned
 * on purpose (see `services.ts`: "an editorial change can never break a public
 * URL"). A per-entity `noindex` column would cover the article and the tool and
 * miss everything else.
 *
 * ### Why the settings store and not a table
 *
 * {@see SiteKeyPolicy::KEY_CUSTOM_SITES} already keeps a JSON list in this same
 * namespace, and the alternative costs more than it returns: the
 * `MigrationRunner` loads every module's migrations into one process against
 * one `phinxlog`, and its preflight aborts *all* of them on a version or class
 * collision. A list of at most a few hundred short strings does not earn that
 * risk.
 *
 * ### The rule the sites enforce, stated here because it is easy to lose
 *
 * An exclusion removes the whole hreflang group, never one URL of it. All three
 * sitemaps emit reciprocal `de-DE`/`en-GB`/`x-default` alternates, and one
 * dangling alternate invalidates the entire set — the German side included. So
 * a pattern matching `/leistungen/beratung` must also take
 * `/en/services/consulting` with it. The pairing differs per site (only the
 * tools tree is a pure prefix operation), which is why the sites own that half
 * and this class owns only the comparison.
 *
 * Never throws: without a database it resolves to an empty list, which is the
 * safe direction — "nothing excluded" keeps a sitemap complete, while a failure
 * that excluded everything would empty it silently.
 */
final class SitemapExclusions
{
    /** Shared with {@see SiteKeyPolicy} — same subject, same panel screen. */
    public const NAMESPACE = 'sites';
    public const KEY = 'sitemap_exclusions';

    /** Generous enough for a taxonomy sweep, small enough to stay a TEXT column. */
    public const MAX_PER_SITE = 200;
    public const MAX_LENGTH = 191;

    /**
     * @param array<string, list<string>> $bySite
     */
    private function __construct(
        private readonly array $bySite,
        /** Whether the stored layer could be read at all (`false` = no DB yet). */
        public readonly bool $storeAvailable,
    ) {
    }

    public static function resolve(?SettingsStoreContract $store): self
    {
        $raw = null;
        $available = false;
        try {
            $raw = $store?->get(self::NAMESPACE, self::KEY);
            $available = $store !== null;
        } catch (\Throwable) {
            // No DB yet — an empty list, which is what an unconfigured site
            // already behaves like.
        }

        return new self(self::decode((string) ($raw ?? '')), $available);
    }

    /**
     * The patterns for one site, or `[]` for a site nobody has configured.
     *
     * @return list<string>
     */
    public function forSite(string $site): array
    {
        return $this->bySite[trim($site)] ?? [];
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->bySite;
    }

    /**
     * Parse a submitted `{site: [pattern, …]}` map, reporting what it dropped.
     *
     * Rejects are handed back rather than discarded, the same reason the custom
     * site list gives: an entry that vanished silently looks saved, and the
     * page the operator meant to hide stays in the sitemap.
     *
     * @param  array<mixed>  $submitted
     * @param  list<string>  $knownSiteIds ids from {@see SiteKeyPolicy::sites()}
     * @return array{0: array<string, list<string>>, 1: list<array{value: string, reason: string}>}
     */
    public static function normalize(array $submitted, array $knownSiteIds): array
    {
        $accepted = [];
        $rejected = [];

        foreach ($submitted as $site => $patterns) {
            $id = trim((string) $site);
            if (!in_array($id, $knownSiteIds, true)) {
                $rejected[] = ['value' => $id, 'reason' => 'Unbekannte Site-Kennung.'];
                continue;
            }
            if (!is_array($patterns)) {
                $rejected[] = ['value' => $id, 'reason' => 'Liste von Pfaden erwartet.'];
                continue;
            }

            $seen = [];
            foreach ($patterns as $pattern) {
                if (!is_scalar($pattern)) {
                    $rejected[] = ['value' => '', 'reason' => 'Pfad ist kein Text.'];
                    continue;
                }
                $raw = trim((string) $pattern);
                if ($raw === '') {
                    continue;
                }
                if (count($seen) >= self::MAX_PER_SITE) {
                    $rejected[] = ['value' => $raw, 'reason' => 'Mehr als ' . self::MAX_PER_SITE . ' Pfade für diese Site.'];
                    continue;
                }

                [$normalized, $reason] = self::normalizePattern($raw);
                if ($normalized === null) {
                    $rejected[] = ['value' => $raw, 'reason' => (string) $reason];
                    continue;
                }
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
            }

            if ($seen !== []) {
                $list = array_keys($seen);
                sort($list, SORT_STRING);
                $accepted[$id] = $list;
            }
        }

        ksort($accepted, SORT_STRING);
        return [$accepted, $rejected];
    }

    /**
     * One submitted pattern, or `null` plus the reason it cannot be used.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normalizePattern(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [null, 'Leerer Pfad.'];
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            return [null, 'Länger als ' . self::MAX_LENGTH . ' Zeichen.'];
        }
        if (str_contains($value, '://')) {
            return [null, 'Vollständige URL statt Pfad — nur der Pfad ab „/".'];
        }
        if ($value[0] !== '/') {
            return [null, 'Muss mit „/" beginnen.'];
        }
        // `//host/path` is protocol-relative and would name another origin.
        if (str_starts_with($value, '//')) {
            return [null, 'Muss mit genau einem „/" beginnen.'];
        }
        if (str_contains($value, '?') || str_contains($value, '#')) {
            return [null, 'Kein Query-String und kein Fragment — der Cache kennt beides nicht.'];
        }
        if (preg_match('/\s/u', $value) === 1) {
            return [null, 'Enthält Leerzeichen.'];
        }
        $stars = substr_count($value, '*');
        if ($stars > 1) {
            return [null, 'Höchstens ein „*".'];
        }
        if ($stars === 1 && !str_ends_with($value, '*')) {
            return [null, 'Das „*" ist nur am Ende erlaubt (Präfix-Muster).'];
        }

        // A trailing `*` is part of the pattern, so only a plain path gets its
        // trailing slash folded away — `/preise/` and `/preise` are one page.
        if ($stars === 0) {
            $value = self::canonicalPath($value);
        }

        return [$value, null];
    }

    /**
     * Does any pattern cover this path?
     *
     * The reference implementation of the comparison the three sites repeat in
     * their own `sitemapExclusions.ts`. Case-sensitive, because URL paths are.
     *
     * @param list<string> $patterns
     */
    public static function matches(string $path, array $patterns): bool
    {
        $subject = self::canonicalPath($path);

        foreach ($patterns as $pattern) {
            $value = trim((string) $pattern);
            if ($value === '') {
                continue;
            }
            if (str_ends_with($value, '*')) {
                $prefix = substr($value, 0, -1);
                if ($prefix === '' || str_starts_with($subject, $prefix)) {
                    return true;
                }
                continue;
            }
            if (self::canonicalPath($value) === $subject) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, list<string>> $bySite */
    public static function encode(array $bySite): string
    {
        return json_encode(
            (object) $bySite,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Trailing slash folded away, root kept as `/`.
     *
     * `/preise` and `/preise/` are the same page to every one of these sites
     * (`trailingSlash: "ignore"` in all three Astro configs), so they must not
     * be two different exclusions.
     */
    private static function canonicalPath(string $path): string
    {
        $value = trim($path);
        if ($value === '' || $value === '/') {
            return '/';
        }
        return rtrim($value, '/') ?: '/';
    }

    /**
     * Stored JSON back into a map, dropping anything that no longer validates.
     *
     * Re-normalising on read rather than trusting the column means a value
     * written by an older shape, or edited straight in the database, cannot put
     * a malformed pattern in front of the sites.
     *
     * @return array<string, list<string>>
     */
    private static function decode(string $raw): array
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

        // Read-side normalisation cannot consult the custom site list (that
        // needs the store this class was just handed), so it accepts whatever
        // site ids are present and only validates the patterns.
        $out = [];
        foreach ($decoded as $site => $patterns) {
            $id = trim((string) $site);
            if ($id === '' || !is_array($patterns)) {
                continue;
            }
            $seen = [];
            foreach ($patterns as $pattern) {
                if (!is_scalar($pattern)) {
                    continue;
                }
                [$normalized] = self::normalizePattern((string) $pattern);
                if ($normalized !== null && !isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                }
            }
            if ($seen !== []) {
                $list = array_keys($seen);
                sort($list, SORT_STRING);
                $out[$id] = $list;
            }
        }

        ksort($out, SORT_STRING);
        return $out;
    }
}
