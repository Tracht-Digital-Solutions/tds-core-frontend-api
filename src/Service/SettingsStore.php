<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use PDO;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * Runtime settings store — a generic namespaced key/value table so third-party
 * config (DeepL keys, Stripe credentials, SMTP, …) is editable in the panel instead
 * of being `.env`-only. Ports the services' `app_setting` model into the panel
 * platform.
 *
 * - **DB-first with env fallback** is the intended read pattern for consumers:
 *   a non-empty stored value wins, else the env var, else a coded default (so
 *   existing `.env` deployments keep working and boot stays DB-free).
 * - **Secrets are AES-256-GCM-encrypted at rest** under `SETTINGS_ENCRYPTION_KEY`.
 *   The admin API returns only masked state (`configured` + `last4`), never a raw
 *   secret; a blank secret on save means "keep the existing value".
 * - The core has no Phinx migration runner yet, so the table **self-bootstraps**
 *   (idempotent `CREATE TABLE IF NOT EXISTS`, once per process) — move to a base
 *   migration when the migrator lands.
 *
 * Namespaces are per-extension (e.g. `blog-cms`, `website-cms`), keeping keys
 * from colliding across extensions that share this one table.
 */
final class SettingsStore implements SettingsStoreContract
{
    private static bool $schemaEnsured = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionKey,
    ) {
    }

    /** Plaintext value for a non-secret key, or $default when absent/empty. */
    public function get(string $namespace, string $key, ?string $default = null): ?string
    {
        $row = $this->row($namespace, $key);
        if ($row === null || (int) $row['is_secret'] === 1) {
            return $default;
        }
        $v = (string) ($row['svalue'] ?? '');
        return $v === '' ? $default : $v;
    }

    /** Decrypted secret for a secret key, or null when absent/undecryptable. */
    public function getSecret(string $namespace, string $key): ?string
    {
        $row = $this->row($namespace, $key);
        if ($row === null || (int) $row['is_secret'] !== 1) {
            return null;
        }
        $stored = (string) ($row['svalue'] ?? '');
        return $stored === '' ? null : self::decrypt($stored, $this->encryptionKey);
    }

    /** Upsert a value. A secret is encrypted at rest. */
    public function set(string $namespace, string $key, string $value, bool $secret): void
    {
        $this->ensureSchema();
        $stored = $secret && $value !== '' ? self::encrypt($value, $this->encryptionKey) : $value;
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_setting (namespace, skey, svalue, is_secret)
             VALUES (:n, :k, :v, :s)
             ON DUPLICATE KEY UPDATE svalue = :v2, is_secret = :s2'
        );
        $stmt->execute([
            ':n' => $namespace, ':k' => $key, ':v' => $stored, ':s' => $secret ? 1 : 0,
            ':v2' => $stored, ':s2' => $secret ? 1 : 0,
        ]);
    }

    public function delete(string $namespace, string $key): void
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare('DELETE FROM app_setting WHERE namespace = :n AND skey = :k');
        $stmt->execute([':n' => $namespace, ':k' => $key]);
    }

    /**
     * Masked view of a namespace for the admin API — a secret is never returned
     * raw, only `configured` + `last4`. Non-secrets return their value.
     *
     * @return list<array<string,mixed>>
     */
    public function allMasked(string $namespace): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT skey, svalue, is_secret FROM app_setting WHERE namespace = :n ORDER BY skey'
        );
        $stmt->execute([':n' => $namespace]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $secret = (int) $r['is_secret'] === 1;
            $stored = (string) ($r['svalue'] ?? '');
            if ($secret) {
                $plain = $stored !== '' ? self::decrypt($stored, $this->encryptionKey) : null;
                $out[] = [
                    'key' => (string) $r['skey'],
                    'secret' => true,
                    'configured' => $plain !== null && $plain !== '',
                    'last4' => $plain !== null && strlen($plain) >= 4 ? substr($plain, -4) : null,
                ];
            } else {
                $out[] = [
                    'key' => (string) $r['skey'],
                    'secret' => false,
                    'value' => $stored,
                ];
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function row(string $namespace, string $key): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT svalue, is_secret FROM app_setting WHERE namespace = :n AND skey = :k LIMIT 1'
        );
        $stmt->execute([':n' => $namespace, ':k' => $key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_setting (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                namespace VARCHAR(64) NOT NULL,
                skey VARCHAR(96) NOT NULL,
                svalue TEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ns_key (namespace, skey)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }

    // --- crypto (AES-256-GCM; stored as "v1:base64(iv|tag|cipher)") ------------

    public static function encrypt(string $plain, string $key): string
    {
        $k = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            // Never persist plaintext under a secret flag — fail loudly instead.
            throw new \RuntimeException('settings: encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored, string $key): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $k = hash('sha256', $key, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
