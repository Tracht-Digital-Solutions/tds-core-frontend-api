<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

/**
 * Semver comparison + npm range satisfaction — the PHP twin of the host's
 * `src/lib/moduleUpdates.ts`.
 *
 * Both halves need the same verdict for a different reason: the panel renders
 * it, and {@see AutoUpdater} acts on it unattended. Keeping the two in sync by
 * hand follows the platform's existing convention for validation (a PHP
 * validator beside a Zod schema) — backends deliberately do not consume the
 * frontend packages. **Change one, change the other.**
 *
 * The rule that matters is the 0.x caret: `^0.1.1` is `>=0.1.1 <0.2.0`, so a
 * module may ship patches freely but crossing its minor needs a repin in the
 * product repo. An auto-update that got this wrong would fire a deploy on every
 * check and never actually change anything.
 */
final class VersionRange
{
    /** @return array{major:int,minor:int,patch:int,pre:string}|null */
    public static function parse(string $version): ?array
    {
        $ok = preg_match(
            '/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/',
            trim($version),
            $m,
        );
        if ($ok !== 1) {
            return null;
        }
        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'pre' => $m[4] ?? '',
        ];
    }

    /**
     * Negative when `$a < $b`, 0 when equal, positive when `$a > $b`. A
     * prerelease sorts BELOW its release — every package repo publishes a `@dev`
     * prerelease on each push to main, and treating those as newer would make
     * the auto-updater deploy continuously.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return 0;
        }
        foreach (['major', 'minor', 'patch'] as $part) {
            if ($pa[$part] !== $pb[$part]) {
                return $pa[$part] <=> $pb[$part];
            }
        }
        if ($pa['pre'] === $pb['pre']) {
            return 0;
        }
        if ($pa['pre'] === '') {
            return 1;
        }
        if ($pb['pre'] === '') {
            return -1;
        }
        return $pa['pre'] <=> $pb['pre'];
    }

    /**
     * Does `$version` satisfy `$range` as a product pins it? Supports `^`, `~`,
     * `>=`, an exact pin and `*`.
     *
     * Returns **null** for a range this cannot parse — "I cannot tell" must stay
     * distinguishable from "no", because the auto-updater treats only an
     * explicit `true` as permission to deploy.
     */
    public static function satisfies(string $version, string $range): ?bool
    {
        $v = self::parse($version);
        $range = trim($range);
        if ($v === null) {
            return null;
        }
        if ($range === '' || $range === '*' || $range === 'latest') {
            return true;
        }
        if (preg_match('/^(\^|~|>=)?\s*(.+)$/', $range, $m) !== 1) {
            return null;
        }
        $operator = $m[1] ?? '';
        $base = self::parse($m[2]);
        if ($base === null) {
            return null;
        }
        if (self::compare($version, $m[2]) < 0) {
            return false;
        }

        return match ($operator) {
            // A caret locks the leftmost NON-ZERO segment.
            '^' => $base['major'] > 0
                ? $v['major'] === $base['major']
                : ($base['minor'] > 0
                    ? $v['major'] === 0 && $v['minor'] === $base['minor']
                    : $v['major'] === 0 && $v['minor'] === 0 && $v['patch'] === $base['patch']),
            '~' => $v['major'] === $base['major'] && $v['minor'] === $base['minor'],
            '>=' => true,
            default => self::compare($version, $m[2]) === 0,
        };
    }
}
