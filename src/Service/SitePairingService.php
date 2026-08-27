<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SiteConnection;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SitePairing;
use Tds\Frontend\Contract\SitePairingDelivery;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/** Ten-minute, hash-only and idempotent site pairing lifecycle. */
final class SitePairingService implements SiteConnections
{
    public const TTL_SECONDS = 600;
    public const TOKEN_PREFIX = 'tdsp_';
    public const FINALIZE_PREFIX = 'tdsf_';

    public function __construct(
        private readonly SiteConnectionStore $store,
        private readonly SiteKeyStore $keys,
        private readonly string $encryptionKey,
        private readonly ?PairingRateLimiter $rateLimiter = null,
        private readonly bool $allowLocalOrigins = false,
        private readonly ?SettingsStoreContract $settings = null,
        /** @var callable|null test transport */
        private $http = null,
    ) {
    }

    public function deliverPairing(SitePairing $pairing, string $apiBase): SitePairingDelivery
    {
        $apiBase = self::origin($apiBase, true, $this->allowLocalOrigins);
        // The later exchange request derives its origin from the HTTP request.
        // Pin the value from this authenticated CMS action first, otherwise a
        // stolen invitation plus a forged Host header could make the site save
        // an attacker's API base.
        $this->store->pinApiBase($pairing->pairingId, $apiBase);
        $url = $pairing->origin . '/tds/connect';
        $body = json_encode([
            'pairing_token' => $pairing->pairingToken,
            'api_base' => $apiBase,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        try {
            $result = $this->http !== null
                ? ($this->http)($url, ['Content-Type: application/json', 'Accept: application/json'], $body)
                : self::post($url, $body, $this->allowLocalOrigins);
        } catch (\Throwable) {
            // Delivery failure is precisely what the fragment-only fallback is
            // for. Do not turn it into a generic CMS 500 that hides that link.
            $result = ['status' => 0];
        }
        $status = (int) ($result['status'] ?? 0);
        $unsafe = ($result['unsafe'] ?? false) === true;
        if ($status >= 200 && $status < 300) {
            $connection = $this->store->get($pairing->resourceType, $pairing->resourceId);
            if ($connection === null || $connection->status !== SiteConnection::CONNECTED) {
                return new SitePairingDelivery(
                    false,
                    SiteConnection::PENDING,
                    $connection,
                    $pairing->installUrl($apiBase),
                    $pairing->expiresAt,
                    'Site hat das Pairing nicht finalisiert',
                );
            }
            return new SitePairingDelivery(
                true,
                SiteConnection::CONNECTED,
                $connection,
                null,
                $pairing->expiresAt,
            );
        }
        return new SitePairingDelivery(
            false,
            SiteConnection::PENDING,
            $this->store->get($pairing->resourceType, $pairing->resourceId),
            $unsafe ? null : $pairing->installUrl($apiBase),
            $pairing->expiresAt,
            $unsafe ? 'Unsicheres oder privates Ziel abgelehnt' : ($status > 0 ? 'HTTP ' . $status : 'Site nicht erreichbar'),
        );
    }

    public function get(string $resourceType, string $resourceId): ?SiteConnection
    {
        [$resourceType, $resourceId] = self::resource($resourceType, $resourceId);
        return $this->store->get($resourceType, $resourceId);
    }

    public function createPairing(
        string $resourceType,
        string $resourceId,
        string $origin,
        string $profile,
        array $bindings = [],
        array $scopes = [],
    ): SitePairing {
        [$resourceType, $resourceId] = self::resource($resourceType, $resourceId);
        $origin = self::origin($origin, false, $this->allowLocalOrigins);
        $profile = self::profile($profile);
        self::assertResourceProfile($resourceType, $profile);
        $bindings = self::bindings($bindings);
        $resourceBinding = $resourceType;
        if (isset($bindings[$resourceBinding]) && (string) $bindings[$resourceBinding] !== $resourceId) {
            throw new SitePairingException('Ressourcenbindung stimmt nicht mit dem CMS-Eintrag überein.');
        }
        $bindings[$resourceBinding] = $resourceId;
        $scopes = self::scopes($scopes !== [] ? $scopes : self::defaultScopes($profile), $profile);
        if (!$this->store->encryptionConfigured()) {
            throw new SitePairingException(
                'SETTINGS_ENCRYPTION_KEY ist für sichere Site-Verbindungen erforderlich.',
                503,
                'encryption_not_configured',
            );
        }

        $publicId = 'pair_' . self::secret(18);
        $token = self::TOKEN_PREFIX . self::secret(32);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        if ($this->rateLimiter !== null && !$this->rateLimiter->allow('create:' . $resourceType . ':' . $resourceId, 5, self::TTL_SECONDS)) {
            throw new SitePairingException('Zu viele Pairing-Versuche. Bitte später erneut versuchen.', 429, 'pairing_rate_limited');
        }
        $this->store->transaction(function () use (
            $publicId, $token, $resourceType, $resourceId, $origin, $profile, $bindings, $scopes, $expiresAt
        ): void {
            $this->store->cancelPending($resourceType, $resourceId, $this->keys);
            $this->store->insertPairing(
                $publicId,
                hash('sha256', $token),
                $resourceType,
                $resourceId,
                $origin,
                $profile,
                $bindings,
                $scopes,
                $expiresAt,
            );
        });

        return new SitePairing(
            $publicId,
            $token,
            $resourceType,
            $resourceId,
            $origin,
            $profile,
            $bindings,
            $scopes,
            gmdate('c', strtotime($expiresAt) ?: time() + self::TTL_SECONDS),
        );
    }

    public function delete(string $resourceType, string $resourceId): bool
    {
        [$resourceType, $resourceId] = self::resource($resourceType, $resourceId);
        return $this->store->delete($resourceType, $resourceId, $this->keys);
    }

    /** @return array<string,mixed> */
    public function exchange(
        string $pairingToken,
        string $profile,
        string $origin,
        string $apiBase,
    ): array {
        $pairingToken = trim($pairingToken);
        if (!str_starts_with($pairingToken, self::TOKEN_PREFIX) || strlen($pairingToken) < 40) {
            throw new SitePairingException('Pairing-Token ist ungültig.', 401, 'invalid_pairing_token');
        }
        $profile = self::profile($profile);
        $origin = self::origin($origin, false, $this->allowLocalOrigins);
        $apiBase = self::origin($apiBase, true, $this->allowLocalOrigins);
        if ($this->rateLimiter !== null && !$this->rateLimiter->allow('exchange:' . $origin, 20, 300)) {
            throw new SitePairingException('Zu viele Pairing-Versuche. Bitte später erneut versuchen.', 429, 'pairing_rate_limited');
        }

        $result = $this->store->transaction(function () use ($pairingToken, $profile, $origin, $apiBase): array|SitePairingException {
            $row = $this->store->pairingByTokenHash(hash('sha256', $pairingToken), true);
            if ($row === null) {
                throw new SitePairingException('Pairing-Token ist unbekannt.', 401, 'invalid_pairing_token');
            }
            $this->assertPairing($row, $profile, $origin, true);
            if ($row['cancelled_at'] !== null) {
                throw new SitePairingException('Pairing wurde ersetzt.', 410, 'pairing_cancelled');
            }
            if (self::isExpired($row)) {
                $this->store->cancelPairing($row, $this->keys);
                return new SitePairingException('Pairing ist abgelaufen.', 410, 'pairing_expired');
            }
            if ($row['finalized_at'] !== null) {
                throw new SitePairingException('Pairing-Token wurde bereits verwendet.', 409, 'pairing_already_used');
            }
            $pinnedApiBase = (string) ($row['api_base'] ?? '');
            if ($pinnedApiBase === '' || !hash_equals($pinnedApiBase, $apiBase)) {
                throw new SitePairingException(
                    'Pairing gehört zu einer anderen API-Origin.',
                    403,
                    'pairing_api_mismatch',
                );
            }

            $finalizeToken = $this->finalizeToken($pairingToken, (string) $row['public_id']);
            if ($row['exchanged_at'] !== null) {
                throw new SitePairingException('Pairing-Token wurde bereits ausgetauscht.', 409, 'pairing_already_exchanged');
            }
            $issued = $this->keys->issueScoped(
                $profile,
                ucfirst($profile) . ' · ' . (string) $row['resource_id'],
                $origin,
                (string) $row['resource_type'],
                (string) $row['resource_id'],
                SiteConnectionStore::bindings($row),
                SiteConnectionStore::scopes($row),
            );
            $secrets = [
                'site_key' => $issued['key'],
                'cache_token' => 'tdsc_' . self::secret(32),
            ];
            $this->store->markExchanged(
                (int) $row['id'],
                hash('sha256', $finalizeToken),
                $issued['id'],
                $secrets['cache_token'],
                $apiBase,
            );

            return $this->exchangePayload($row, $finalizeToken, $secrets, $apiBase);
        });
        if ($result instanceof SitePairingException) {
            throw $result;
        }
        return $result;
    }

    public function finalize(string $pairingId, string $finalizeToken, string $profile, string $origin): SiteConnection
    {
        $pairingId = trim($pairingId);
        $finalizeToken = trim($finalizeToken);
        $profile = self::profile($profile);
        $origin = self::origin($origin, false, $this->allowLocalOrigins);
        if ($pairingId === '' || !str_starts_with($finalizeToken, self::FINALIZE_PREFIX)) {
            throw new SitePairingException('Finalisierung ist ungültig.', 401, 'invalid_finalize_token');
        }

        $result = $this->store->transaction(function () use ($pairingId, $finalizeToken, $profile, $origin): SiteConnection|SitePairingException {
            $row = $this->store->pairingByPublicId($pairingId, true);
            if ($row === null) {
                throw new SitePairingException('Pairing ist unbekannt.', 404, 'pairing_not_found');
            }
            $this->assertPairing($row, $profile, $origin, true);
            if ($row['cancelled_at'] !== null) {
                throw new SitePairingException('Pairing wurde ersetzt.', 410, 'pairing_cancelled');
            }
            if ($row['finalized_at'] === null && self::isExpired($row)) {
                $this->store->cancelPairing($row, $this->keys);
                return new SitePairingException('Pairing ist abgelaufen.', 410, 'pairing_expired');
            }
            $expected = (string) ($row['finalize_hash'] ?? '');
            if ($expected === '' || !hash_equals($expected, hash('sha256', $finalizeToken))) {
                throw new SitePairingException('Finalize-Token wurde abgelehnt.', 401, 'invalid_finalize_token');
            }

            if ($row['finalized_at'] !== null) {
                $connection = $this->store->get((string) $row['resource_type'], (string) $row['resource_id']);
                if ($connection === null) {
                    throw new SitePairingException('Finalisierte Verbindung fehlt.', 503, 'connection_missing');
                }
                $this->ensureCors($origin);
                return $connection;
            }
            if ($row['exchanged_at'] === null || $row['pending_site_key_id'] === null) {
                throw new SitePairingException('Pairing wurde noch nicht ausgetauscht.', 409, 'pairing_not_exchanged');
            }
            $connection = $this->store->finalize($row, $this->keys);
            $this->ensureCors($origin);
            return $connection;
        });
        if ($result instanceof SitePairingException) {
            throw $result;
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private function assertPairing(array $row, string $profile, string $origin, bool $allowExpired = false): void
    {
        if (!hash_equals((string) $row['profile'], $profile) || !hash_equals((string) $row['origin'], $origin)) {
            throw new SitePairingException('Pairing gehört zu einer anderen Site.', 403, 'pairing_binding_mismatch');
        }
        if (!$allowExpired && self::isExpired($row)) {
            throw new SitePairingException('Pairing ist abgelaufen.', 410, 'pairing_expired');
        }
    }

    /** @param array<string,mixed> $row */
    private static function isExpired(array $row): bool
    {
        return (strtotime((string) $row['expires_at']) ?: 0) < time();
    }

    private function ensureCors(string $origin): void
    {
        if ($this->settings === null || in_array($origin, CorsConfig::BASELINE, true)) {
            return;
        }
        $current = CorsConfig::split((string) ($this->settings->get(CorsConfig::NAMESPACE, CorsConfig::KEY_ORIGINS, '') ?? ''));
        if (in_array($origin, $current, true)) {
            return;
        }
        $current[] = $origin;
        $this->settings->set(
            CorsConfig::NAMESPACE,
            CorsConfig::KEY_ORIGINS,
            implode("\n", array_values(array_unique($current))),
            false,
        );
    }

    /** @param array<string,mixed> $row @param array{site_key:string,cache_token:string} $secrets */
    private function exchangePayload(array $row, string $finalizeToken, array $secrets, string $apiBase): array
    {
        $runtime = [
            'apiBase' => $apiBase,
            'authBase' => $apiBase . '/auth',
            'loginUrl' => 'https://auth.tracht-digital.de',
            'contactUrl' => $apiBase . '/contact',
            'liveChatFrontend' => (string) $row['profile'],
        ];
        return [
            'pairing_id' => (string) $row['public_id'],
            'finalize_token' => $finalizeToken,
            'connection' => [
                'version' => 1,
                'profile' => (string) $row['profile'],
                'origin' => (string) $row['origin'],
                'api_base' => $apiBase,
                'site_key' => $secrets['site_key'],
                'cache_token' => $secrets['cache_token'],
                'resource' => [
                    'type' => (string) $row['resource_type'],
                    'id' => (string) $row['resource_id'],
                ],
                'bindings' => (object) SiteConnectionStore::bindings($row),
                'scopes' => SiteConnectionStore::scopes($row),
                'runtime' => (object) $runtime,
            ],
        ];
    }

    private function finalizeToken(string $pairingToken, string $publicId): string
    {
        $raw = hash_hmac('sha256', $publicId . "\0" . $pairingToken, $this->encryptionKey, true);
        return self::FINALIZE_PREFIX . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{string,string} */
    private static function resource(string $type, string $id): array
    {
        $type = strtolower(trim($type));
        $type = match ($type) {
            'landing', 'landingpage', 'site' => 'website',
            default => $type,
        };
        $id = trim($id);
        if (!in_array($type, ['blog', 'website', 'tools'], true)) {
            throw new SitePairingException('resource_type muss blog, website oder tools sein.');
        }
        if ($id === '' || strlen($id) > 191 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new SitePairingException('resource_id ist ungültig.');
        }
        return [$type, $id];
    }

    private static function profile(string $profile): string
    {
        $profile = strtolower(trim($profile));
        $profile = match ($profile) {
            'landing', 'website' => 'landingpage',
            default => $profile,
        };
        if (!in_array($profile, ['blog', 'landingpage', 'tools'], true)) {
            throw new SitePairingException('profile muss blog, landingpage oder tools sein.');
        }
        return $profile;
    }

    public static function origin(string $origin, bool $api = false, bool $allowLocal = false): string
    {
        $value = trim($origin);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new SitePairingException(($api ? 'API-' : '') . 'Origin ist ungültig.');
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            throw new SitePairingException(($api ? 'API-' : '') . 'Origin ist ungültig.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $literalIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (
            ($scheme !== 'https' && !($allowLocal && $scheme === 'http' && $loopback))
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && preg_match('#^/+$#', $path) !== 1)
        ) {
            throw new SitePairingException(($api ? 'API-' : '') . 'Origin muss HTTPS ohne Pfad sein.');
        }
        if (!$allowLocal && ($loopback || $literalIp || !str_contains($host, '.') || str_ends_with($host, '.local'))) {
            throw new SitePairingException(($api ? 'API-' : '') . 'Origin darf kein lokales oder direktes IP-Ziel sein.');
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /** @param array<string,mixed> $bindings @return array<string,mixed> */
    private static function bindings(array $bindings): array
    {
        $json = json_encode((object) $bindings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 16384) {
            throw new SitePairingException('bindings sind zu groß.');
        }
        return $bindings;
    }

    /** @param list<mixed> $scopes @return list<string> */
    private static function scopes(array $scopes, string $profile): array
    {
        $allowed = array_fill_keys(self::defaultScopes($profile), true);
        $out = [];
        foreach ($scopes as $scope) {
            $scope = rtrim(trim((string) $scope), '/');
            if ($scope === '' || !isset($allowed[$scope])) {
                throw new SitePairingException('scope ist ungültig.');
            }
            $out[$scope] = true;
        }
        if ($out === [] || count($out) > 32) {
            throw new SitePairingException('Mindestens ein und höchstens 32 scopes sind erforderlich.');
        }
        return array_keys($out);
    }

    /** @return list<string> */
    private static function defaultScopes(string $profile): array
    {
        return match ($profile) {
            'blog', 'landingpage' => [
                '/content/blog',
                '/content/topics',
                '/content/snippets',
                '/content/landing',
                '/content/legal',
            ],
            'tools' => ['/tools/catalog', '/tools/registry', '/tools/guides'],
        };
    }

    private static function assertResourceProfile(string $resourceType, string $profile): void
    {
        $expected = match ($resourceType) {
            'blog' => 'blog',
            'website' => 'landingpage',
            'tools' => 'tools',
        };
        if (!hash_equals($expected, $profile)) {
            throw new SitePairingException('CMS-Ressource und Site-Profil passen nicht zusammen.');
        }
    }

    private static function secret(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** @return array{status:int,unsafe?:bool} */
    private static function post(string $url, string $body, bool $allowLocal): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : 443;
        $resolve = [];
        if (!$allowLocal) {
            $addresses = $host !== '' ? gethostbynamel($host) : false;
            if ($addresses === false || $addresses === []) {
                return ['status' => 0];
            }
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return ['status' => 0, 'unsafe' => true];
                }
            }
            // Pin the verified address so a second DNS answer cannot redirect
            // the token to a private host between validation and connect.
            $resolve[] = $host . ':' . $port . ':' . $addresses[0];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            // Pairing credentials are as sensitive as cache tokens. Never let
            // libcurl replay them to a redirect target.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => $resolve,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status];
    }
}
