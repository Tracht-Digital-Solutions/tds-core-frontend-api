<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The effective CORS allow-list, read **DB-first with an env fallback** — the
 * platform's standard pattern for runtime config ({@see SettingsStore}
 * namespace `cors`, editable under Einstellungen → *CORS / Freigegebene
 * Origins*).
 *
 * Why this joined the settings store, in the words of the rule the mail and
 * IMAP moves already established: *a feature that can only be configured by
 * editing a file on the host is, on this Plesk host, a feature nobody has.*
 * Allowing a new origin — a second customer domain, a staging host, a local
 * dev port — meant an SSH edit of `services/frontend/.env` and a restart, on a
 * host whose whole install model is "no SSH". So in practice the list was
 * whatever the installer wrote once, forever.
 *
 * Three layers, and they only ever ADD:
 *
 *   1. {@see BASELINE} — the first-party `*.tracht-digital.de` surfaces, coded
 *      in. Always present.
 *   2. `CORS_ALLOWED_ORIGINS` from the host `.env`. Kept as a fallback so an
 *      existing deployment behaves identically after this change.
 *   3. The stored `allowed_origins` row, edited in the panel.
 *
 * **The union is deliberate — this is the one settings namespace where DB does
 * NOT override env.** Everywhere else a stored value outranks the file, because
 * the failure mode is an `.env` written at install time shadowing the form. The
 * failure mode HERE is worse and it is unrecoverable from the panel: an admin
 * who removes the origin their own frontend runs on locks the panel out of the
 * API, and the only surface that could put the origin back is the one that just
 * stopped working. Union + a coded baseline means no edit made in the browser
 * can cost you the browser.
 *
 * Never throws. Without a database — the state the frontend service is in until
 * `services/frontend/.env` + `tds_frontend` exist — it resolves to baseline +
 * env, which is exactly today's behaviour.
 */
final class CorsConfig
{
    public const NAMESPACE = 'cors';
    public const KEY_ORIGINS = 'allowed_origins';

    /**
     * The first-party surfaces, always allowed.
     *
     * All of these are TDS's own domains, and several of them call with
     * credentials (the live-chat bubble on the public site and the blog, the
     * account menu on blog + tools). A missing Allow-Origin here is silent —
     * the browser drops the response, the widget renders its generic failure,
     * and nothing is logged anywhere — which is why it is not left to a file.
     *
     * @var list<string>
     */
    public const BASELINE = [
        'https://tracht-digital.de',
        // A visitor who lands on `www.` posts the contact form from an origin
        // that is not the apex.
        'https://www.tracht-digital.de',
        'https://blog.tracht-digital.de',
        'https://management.tracht-digital.de',
        'https://app.tracht-digital.de',
        'https://tools.tracht-digital.de',
        'https://auth.tracht-digital.de',
    ];

    private function __construct(
        /** @var list<string> Extras from `CORS_ALLOWED_ORIGINS`, baseline removed. */
        public readonly array $fromEnv,
        /** @var list<string> Extras stored in the panel, baseline + env removed. */
        public readonly array $custom,
        /** Whether the stored layer could be read at all (`false` = no DB yet). */
        public readonly bool $storeAvailable,
    ) {
    }

    /** @param callable(string,?string):string $env The Bootstrap env reader. */
    public static function resolve(?SettingsStoreContract $store, callable $env): self
    {
        $fromEnv = self::split((string) $env('CORS_ALLOWED_ORIGINS', ''));

        $stored = null;
        try {
            $stored = $store?->get(self::NAMESPACE, self::KEY_ORIGINS);
        } catch (\Throwable) {
            // No DB yet — env-only, which is the pre-settings behaviour.
            $stored = null;
        }

        $custom = self::split((string) ($stored ?? ''));

        return new self(
            fromEnv: array_values(array_diff($fromEnv, self::BASELINE)),
            custom: array_values(array_diff($custom, self::BASELINE, $fromEnv)),
            storeAvailable: $store !== null,
        );
    }

    /**
     * The full effective allow-list.
     *
     * @return list<string>
     */
    public function origins(): array
    {
        return array_values(array_unique([...self::BASELINE, ...$this->fromEnv, ...$this->custom]));
    }

    /**
     * The two layers that need no database: baseline + `CORS_ALLOWED_ORIGINS`.
     *
     * `CorsMiddleware` checks these FIRST and only consults the stored layer
     * when they do not already cover the origin — which is what keeps the
     * settings lookup off the hot path. The middleware is outermost, so it runs
     * on literally every request including preflights; resolving the store
     * unconditionally would put a PDO connection attempt in front of the whole
     * API, and on a host whose database is down or firewalled that is not a
     * slow request, it is a hung one. Requests from the first-party frontends —
     * i.e. nearly all of them — never reach the database here at all.
     *
     * @param  callable(string,?string):string $env
     * @return list<string>
     */
    public static function staticOrigins(callable $env): array
    {
        return array_values(array_unique([
            ...self::BASELINE,
            ...self::split((string) $env('CORS_ALLOWED_ORIGINS', '')),
        ]));
    }

    /**
     * What the settings page shows: every effective origin with the layer it
     * came from, so an admin can see at a glance which entries they can edit
     * (`db`) and which are fixed (`baseline`) or live in the host's file
     * (`env`). Without the layer an unremovable entry looks like a bug.
     *
     * @return array{origins:list<array{origin:string,source:string}>,custom:list<string>,store_available:bool}
     */
    public function status(): array
    {
        $rows = [];
        foreach (self::BASELINE as $origin) {
            $rows[] = ['origin' => $origin, 'source' => 'baseline'];
        }
        foreach ($this->fromEnv as $origin) {
            $rows[] = ['origin' => $origin, 'source' => 'env'];
        }
        foreach ($this->custom as $origin) {
            $rows[] = ['origin' => $origin, 'source' => 'db'];
        }
        return [
            'origins' => $rows,
            'custom' => $this->custom,
            'store_available' => $this->storeAvailable,
        ];
    }

    /**
     * Split a stored/env list. Commas AND newlines, because the `.env` form is
     * comma-separated while a textarea in the panel is one per line, and an
     * operator will paste one into the other.
     *
     * @return list<string>
     */
    public static function split(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Normalise one operator-typed origin to the exact form a browser sends in
     * the `Origin` header, or `null` with a reason when it cannot be one.
     *
     * This is not pedantry. The comparison is an exact string match, so every
     * near-miss fails **silently and permanently**: the admin saves
     * `https://kunde.de/` (the single most common paste error), sees it listed,
     * and the site it was meant to unblock stays blocked with no error anywhere
     * to connect the two.
     *
     * @return array{0:?string,1:?string} [normalised, reason it was rejected]
     */
    public static function normalizeOrigin(string $value): array
    {
        $raw = trim($value);
        if ($raw === '') {
            return [null, 'leer'];
        }

        // `*` deserves its own message. An admin reaching for it wants "allow
        // everything", but this list is served WITH
        // `Access-Control-Allow-Credentials: true`, and the spec forbids the
        // wildcard in that combination — the browser rejects the response. So
        // it would not loosen the policy, it would break every request.
        if (str_contains($raw, '*')) {
            return [null, 'Platzhalter sind nicht möglich: mit Cookies (Allow-Credentials) verbietet der Standard „*“ — der Browser würde jede Antwort verwerfen.'];
        }

        $trimmed = rtrim($raw, '/');
        $parts = parse_url($trimmed);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return [null, 'Kein gültiger Origin — erwartet wird z. B. https://example.de'];
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return [null, 'Nur http:// oder https://'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return [null, 'Ein Origin enthält keine Zugangsdaten.'];
        }
        // A path, query or fragment means this is a URL, not an origin. Silently
        // stripping it would be worse than refusing: the admin would never learn
        // that the thing they pasted is not what gets compared.
        if (($parts['path'] ?? '') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
            return [null, 'Nur Schema, Host und ggf. Port — kein Pfad (z. B. https://example.de, nicht https://example.de/app).'];
        }

        $origin = $scheme . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return [$origin, null];
    }

    /**
     * Normalise a whole submitted list, dropping the baseline (it is implicit)
     * and reporting every entry that could not be one.
     *
     * @param  list<string> $values
     * @return array{0:list<string>,1:list<array{value:string,reason:string}>}
     */
    public static function normalizeList(array $values): array
    {
        $accepted = [];
        $rejected = [];
        foreach ($values as $value) {
            [$origin, $reason] = self::normalizeOrigin((string) $value);
            if ($origin === null) {
                if (trim((string) $value) !== '') {
                    $rejected[] = ['value' => trim((string) $value), 'reason' => (string) $reason];
                }
                continue;
            }
            if (in_array($origin, self::BASELINE, true) || in_array($origin, $accepted, true)) {
                continue;
            }
            $accepted[] = $origin;
        }
        return [$accepted, $rejected];
    }
}
