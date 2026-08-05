<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

/**
 * Reads the **published** version of a platform package from GitHub Packages.
 *
 * The frontend products are composed at BUILD time from npm packages
 * (`@tracht-digital-solutions/tds-core-frontend`, `…/tds-ext-*`), each pinned
 * with a caret range in the product's `package.json`. The panel's Module page
 * shows what a rebuild would install, which means asking the registry what
 * `dist-tags.latest` currently is — the browser cannot do that itself
 * (GitHub Packages requires a `read:packages` token even for public packages,
 * and that token must never reach the client), so the API proxies it.
 *
 * MUST NOT throw: an expired token or a flaky registry degrades the page to
 * "unbekannt" rather than 500ing it. Every failure returns null and is
 * reported through {@see lastError()}.
 *
 * @see https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-npm-registry
 */
final class PackageRegistry
{
    /** GitHub Packages npm endpoint. */
    private const BASE = 'https://npm.pkg.github.com/';

    /**
     * Only first-party packages may be looked up. Without this the endpoint is
     * a generic outbound HTTP proxy for anyone who reaches the admin routes —
     * the classic SSRF shape. The scope is fixed, not configurable.
     */
    private const ALLOWED = '#^@tracht-digital-solutions/[a-z0-9][a-z0-9._-]{0,80}$#';

    /** Upper bound on one batch — the admin panel composes ~17 packages. */
    private const MAX_PACKAGES = 40;

    private string $lastError = '';

    /**
     * @param string   $token A `read:packages` PAT. Empty ⇒ unconfigured (no lookups).
     * @param callable|null $http Injected transport for tests:
     *        `fn(string $url, array $headers): array{status:int,body:string,error:string}`.
     */
    public function __construct(
        private readonly string $token,
        private $http = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** True for a package name this client is willing to resolve. */
    public static function isAllowed(string $package): bool
    {
        return preg_match(self::ALLOWED, $package) === 1;
    }

    /**
     * `dist-tags.latest` of one package, or null when unconfigured, not
     * allow-listed, missing (404) or unreachable.
     */
    public function latest(string $package): ?string
    {
        if ($this->token === '' || !self::isAllowed($package)) {
            $this->lastError = $this->token === '' ? 'registry token not configured' : 'package not allowed';
            return null;
        }

        // npm addresses a scoped package with the slash percent-encoded.
        $url = self::BASE . str_replace('/', '%2F', $package);
        $res = $this->request($url);
        if ($res['status'] !== 200) {
            $this->lastError = $res['error'] !== ''
                ? $res['error']
                : sprintf('registry responded HTTP %d for %s', $res['status'], $package);
            return null;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            $this->lastError = 'registry returned malformed JSON';
            return null;
        }
        $latest = $data['dist-tags']['latest'] ?? null;
        if (!is_string($latest) || $latest === '') {
            $this->lastError = 'registry response carries no dist-tags.latest';
            return null;
        }
        return $latest;
    }

    /**
     * Batch lookup. Input order is irrelevant; the result is keyed by package
     * name with null for anything that could not be resolved, so the caller can
     * render a row per requested package either way.
     *
     * @param  string[] $packages
     * @return array<string, ?string>
     */
    public function latestMany(array $packages): array
    {
        $unique = array_values(array_unique(array_filter($packages, 'is_string')));
        $unique = array_slice($unique, 0, self::MAX_PACKAGES);

        $out = [];
        foreach ($unique as $package) {
            $out[$package] = $this->latest($package);
        }
        return $out;
    }

    /**
     * @return array{status:int, body:string, error:string}
     */
    private function request(string $url): array
    {
        if ($this->http !== null) {
            /** @var array{status:int, body:string, error:string} $res */
            $res = ($this->http)($url, $this->headers());
            return $res;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $this->headers(),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /** @return string[] */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.npm.install-v1+json, application/json',
            'User-Agent: tds-core-frontend-api',
        ];
    }
}
