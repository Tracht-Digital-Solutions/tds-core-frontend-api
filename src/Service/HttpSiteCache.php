<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\SiteCache;

/**
 * Tells a public site to re-render the cached HTML of the pages one content
 * change affects — the base's implementation of {@see SiteCache}.
 *
 * The public sites (landingpage, blog, tools) render on demand and store each
 * rendered page as a plain file the web server hands out directly. A saved
 * block, post or guide is therefore invisible until its page is rendered
 * again; this is the call that asks for it, and it replaces a full CI rebuild
 * for everything that is merely *content*.
 *
 * ### What it does NOT do
 *
 * It never throws, never retries and never reports failure to the caller.
 * A site that is down, moved or simply not configured yet must not turn "save
 * this article" into an error: the article is saved either way, the public
 * page stays a little stale, and the panel has a rebuild button to catch up.
 * Failures go to `error_log`, exactly like the CMS extensions' RebuildTrigger.
 *
 * ### Three details that are easy to get wrong
 *
 * - **`Content-Type: application/json` is mandatory**, not cosmetic. The
 *   receiving endpoint is an Astro route, and Astro's `security.checkOrigin`
 *   treats a cross-site POST with a form-ish content type as CSRF: it answers
 *   *"Cross-site POST form submissions are forbidden"*, a message that says
 *   nothing about content types and sends the reader looking at tokens.
 * - **The timeouts are short and deliberate.** This runs inside the request
 *   that saved the content. A site that accepts a connection and then hangs
 *   would otherwise hold the editor's save open until PHP's own limit.
 * - **Redirects are refused.** The request carries the secret
 *   `X-TDS-Cache-Token` as an ordinary custom header. libcurl reuses custom
 *   headers on redirected requests, including a redirect to another host, so
 *   following one would hand that host the token. Cache URLs are configured as
 *   exact origins; an http-to-https move must be saved as the https origin.
 */
final class HttpSiteCache implements SiteCache
{
    /** Connect + total, in seconds. A rebuild renders pages, so allow some room. */
    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 15;

    /**
     * @param callable|null $http Injected transport for tests:
     *        `fn(string $url, array $headers, string $body): array{status:int,error:string}`.
     */
    public function __construct(private $http = null)
    {
    }

    public function isConfigured(string $baseUrl, ?string $token): bool
    {
        return self::normaliseBase($baseUrl) !== null && $token !== null && $token !== '';
    }

    public function rebuild(string $baseUrl, ?string $token, array $events): void
    {
        $base = self::normaliseBase($baseUrl);
        if ($base === null || $token === null || $token === '' || $events === []) {
            // Not configured, or nothing to say. Silent on purpose: an
            // extension whose site has no cache URL yet would otherwise log on
            // every single save.
            return;
        }

        $payload = json_encode(
            ['events' => array_map(
                static fn (CacheEvent $e): array => $e->toArray(),
                array_values(array_filter($events, static fn ($e): bool => $e instanceof CacheEvent)),
            )],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($payload === false) {
            error_log('[tds-site-cache] could not encode the event payload');
            return;
        }

        $url = $base . '/tds/cache/rebuild';
        $headers = [
            'Content-Type: application/json',
            'X-TDS-Cache-Token: ' . $token,
            'Accept: application/json',
            'User-Agent: tds-core-frontend-api',
        ];

        $result = $this->http !== null
            ? ($this->http)($url, $headers, $payload)
            : self::post($url, $headers, $payload);

        $status = (int) ($result['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }

        error_log(sprintf(
            '[tds-site-cache] rebuild at %s failed: HTTP %d %s',
            $url,
            $status,
            (string) ($result['error'] ?? ''),
        ));
    }

    /**
     * Trim a configured base URL to an HTTP(S) origin, or null when unusable.
     *
     * A trailing slash is the commonest thing an operator pastes, and
     * `https://blog.example.de//tds/cache/rebuild` is not the same path — the
     * cache would answer 404 and the panel would report a green save with a
     * red log line nobody reads.
     */
    private static function normaliseBase(string $baseUrl): ?string
    {
        $trimmed = trim($baseUrl);
        if (
            $trimmed === ''
            || preg_match('/[\x00-\x20\x7f]/', $trimmed) === 1
            || filter_var($trimmed, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (
            ($scheme !== 'http' && $scheme !== 'https')
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && preg_match('#^/+$#', $path) !== 1)
        ) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    /** @return array{status:int,error:string} */
    private static function post(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'error' => 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            // NEVER follow: CURLOPT_HTTPHEADER custom headers are reused on a
            // redirected request, including to another host. This one carries
            // the cache token. An http→https move must be configured directly.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string) curl_error($ch) : '';
        curl_close($ch);

        return ['status' => $status, 'error' => $error];
    }
}
