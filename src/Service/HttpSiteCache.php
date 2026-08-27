<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\CacheResult;
use Tds\Frontend\Contract\ReportingSiteCache;
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
 * It never throws and never retries. Instead it returns a truthful cache
 * report. A site that is down, moved or simply not configured yet must not
 * turn "save this article" into an error: the article is saved either way,
 * while the response can still show that the public page stayed stale.
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
final class HttpSiteCache implements ReportingSiteCache
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
        $this->rebuildWithResult($baseUrl, $token, $events);
    }

    public function rebuildWithResult(string $baseUrl, ?string $token, array $events): CacheResult
    {
        $base = self::normaliseBase($baseUrl);
        if ($base === null || $token === null || $token === '') {
            // Not configured, or nothing to say. Silent on purpose: an
            // extension whose site has no cache URL yet would otherwise log on
            // every single save.
            return new CacheResult(CacheResult::NOT_CONFIGURED);
        }
        if ($events === []) {
            return new CacheResult(CacheResult::SKIPPED);
        }

        $validEvents = array_values(array_filter($events, static fn ($event): bool => $event instanceof CacheEvent));
        if ($validEvents === []) {
            return new CacheResult(CacheResult::SKIPPED);
        }

        $payload = json_encode(
            ['events' => array_map(static fn (CacheEvent $event): array => $event->toArray(), $validEvents)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($payload === false) {
            error_log('[tds-site-cache] could not encode the event payload');
            return new CacheResult(CacheResult::FAILED, failed: [['error' => 'encode_failed']]);
        }

        $url = $base . '/tds/cache/rebuild';
        $headers = [
            'Content-Type: application/json',
            'X-TDS-Cache-Token: ' . $token,
            'Accept: application/json',
            'User-Agent: tds-core-frontend-api',
        ];

        try {
            $result = $this->http !== null
                ? ($this->http)($url, $headers, $payload)
                : self::post($url, $headers, $payload);
        } catch (\Throwable $error) {
            error_log(sprintf('[tds-site-cache] rebuild at %s failed before response: %s', $url, $error->getMessage()));
            return new CacheResult(CacheResult::FAILED, failed: [['status' => 0, 'error' => 'transport_failed']]);
        }

        $status = (int) ($result['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string) ($result['body'] ?? ''), true);
            if (!is_array($decoded)) {
                error_log(sprintf('[tds-site-cache] rebuild at %s returned invalid JSON', $url));
                return new CacheResult(CacheResult::FAILED, failed: [['status' => $status, 'error' => 'invalid_response']]);
            }

            $rebuilt = self::stringList($decoded['rebuilt'] ?? []);
            $skipped = self::stringList($decoded['skipped'] ?? []);
            $failed = is_array($decoded['failed'] ?? null) ? array_values($decoded['failed']) : [];
            $unknown = is_array($decoded['unknownEvents'] ?? null) ? array_values($decoded['unknownEvents']) : [];
            $resultStatus = $failed !== [] || $unknown !== []
                ? CacheResult::FAILED
                : ($skipped !== [] ? CacheResult::SKIPPED : CacheResult::REFRESHED);

            return new CacheResult($resultStatus, $rebuilt, $skipped, $failed, $unknown);
        }

        error_log(sprintf(
            '[tds-site-cache] rebuild at %s failed: HTTP %d %s',
            $url,
            $status,
            (string) ($result['error'] ?? ''),
        ));
        return new CacheResult(CacheResult::FAILED, failed: [[
            'status' => $status,
            'error' => (string) ($result['error'] ?? ''),
        ]]);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn ($item): bool => is_string($item)));
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

    /** @return array{status:int,error:string,body:string} */
    private static function post(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'error' => 'curl_init failed', 'body' => ''];
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

        return ['status' => $status, 'error' => $error, 'body' => is_string($ok) ? $ok : ''];
    }
}
