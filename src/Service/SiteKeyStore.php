<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use PDO;
use Tds\Frontend\Contract\SiteKeyIdentity;
use Tds\Frontend\Contract\SiteKeys;

/**
 * Site keys — the credential that binds a public static site (landingpage,
 * blog, tools, auth, or a custom one) to this API.
 *
 * ### Why this is NOT a row in `app_setting`
 *
 * The settings store holds one value per namespace+key. A site has *several*
 * keys over its life (issue the new one, deploy it, revoke the old one), and
 * each carries metadata the panel exists to show — when it was created, when it
 * was last used, from which origin. That is a table, not a setting.
 *
 * ### Only a hash is stored
 *
 * The plaintext exists once, in the response of `POST /admin/sites`, and is
 * never recoverable afterwards. Two consequences worth stating because both are
 * deliberate: a lost key is replaced, not looked up; and this store needs no
 * `SETTINGS_ENCRYPTION_KEY`, so it keeps working on a host where that variable
 * was never set — which, for a credential whose whole job is to prove a site is
 * connected, is the difference between a feature and a footnote.
 *
 * Verification is a single indexed lookup on the SHA-256 digest. There is no
 * loop over candidate rows and therefore no string comparison whose duration
 * depends on how much of the key was right.
 *
 * ### Self-bootstrapping, and never throwing
 *
 * The base has no Phinx migrator wired (same reasoning as {@see SettingsStore}),
 * so the table is created idempotently on first use. Every public method
 * degrades to "no keys" rather than throwing: this sits in front of the public
 * read surface, and a service without a database must still serve content, not
 * answer 500 on every content route.
 */
final class SiteKeyStore implements SiteKeys
{
    private static bool $schemaEnsured = false;

    /**
     * Prefix of every issued key. Distinctive on purpose: it makes a leaked key
     * greppable in a build log, a dist/ directory or a pasted snippet.
     */
    public const KEY_PREFIX = 'tdsk_';

    /** How much of the key is stored in the clear for display. */
    private const PREFIX_LEN = 18;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SiteKeyPolicy $policy,
    ) {
    }

    public function enforcement(): string
    {
        return $this->policy->enforcement;
    }

    /**
     * Issue a key for a site. The returned plaintext is the ONLY time it exists.
     *
     * @return array{id: int, key: string, key_prefix: string}
     */
    public function issue(string $site, string $label = '', string $origin = ''): array
    {
        $this->ensureSchema();

        $slug = self::slug($site);
        $key = self::KEY_PREFIX . $slug . '_' . self::randomSecret();
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_site_key (site, label, origin, key_prefix, key_hash, created_at)
             VALUES (:site, :label, :origin, :prefix, :hash, NOW())'
        );
        $stmt->execute([
            ':site' => $site,
            ':label' => $label,
            ':origin' => $origin,
            ':prefix' => substr($key, 0, self::PREFIX_LEN),
            ':hash' => hash('sha256', $key),
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'key' => $key,
            'key_prefix' => substr($key, 0, self::PREFIX_LEN),
        ];
    }

    public function verify(string $key, ?string $site = null, ?string $origin = null): ?SiteKeyIdentity
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare(
                'SELECT id, site, label, origin FROM app_site_key
                 WHERE key_hash = :hash AND revoked_at IS NULL LIMIT 1'
            );
            $stmt->execute([':hash' => hash('sha256', $key)]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            // No database, or a schema this build does not know. A site key
            // cannot be verified, which is not the same as being rejected —
            // the caller decides, and with enforcement `off` it serves.
            return null;
        }

        if ($row === false) {
            return null;
        }
        // A key belongs to exactly one site. Callers that care which one —
        // `POST /tools/registry` — pass it here rather than trusting a `site`
        // field the caller sent alongside the key.
        if ($site !== null && (string) $row['site'] !== $site) {
            return null;
        }

        $this->touch((int) $row['id'], $origin, null);

        return new SiteKeyIdentity(
            (int) $row['id'],
            (string) $row['site'],
            (string) ($row['label'] ?? ''),
            (string) ($row['origin'] ?? ''),
        );
    }

    /** Record a use. Never throws — bookkeeping must not fail a served request. */
    public function touch(int $id, ?string $origin, ?string $apiBase): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE app_site_key
                    SET last_used_at = NOW(),
                        last_used_origin = COALESCE(:origin, last_used_origin),
                        last_used_api_base = COALESCE(:api_base, last_used_api_base)
                  WHERE id = :id'
            );
            $stmt->execute([
                ':origin' => $origin === null || $origin === '' ? null : substr($origin, 0, 191),
                ':api_base' => $apiBase === null || $apiBase === '' ? null : substr($apiBase, 0, 191),
                ':id' => $id,
            ]);
        } catch (\Throwable) {
            // Ignored on purpose.
        }
    }

    /** Mark a key unusable. Returns false when it did not exist (or is already revoked). */
    public function revoke(int $id): bool
    {
        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare(
                'UPDATE app_site_key SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL'
            );
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every key, newest first, grouped-ready for the panel. Never the plaintext.
     *
     * Revoked keys are included and flagged rather than deleted: "this site had
     * a key and somebody revoked it on the 3rd" is exactly the question the page
     * exists to answer, and a row that vanishes answers nothing.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        try {
            $this->ensureSchema();
            $rows = $this->pdo->query(
                'SELECT id, site, label, origin, key_prefix, created_at,
                        last_used_at, last_used_origin, last_used_api_base, revoked_at
                   FROM app_site_key ORDER BY created_at DESC, id DESC'
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'site' => (string) $r['site'],
                'label' => (string) ($r['label'] ?? ''),
                'origin' => (string) ($r['origin'] ?? ''),
                'key_prefix' => (string) $r['key_prefix'],
                'created_at' => (string) $r['created_at'],
                'last_used_at' => $r['last_used_at'] !== null ? (string) $r['last_used_at'] : null,
                'last_used_origin' => $r['last_used_origin'] !== null ? (string) $r['last_used_origin'] : null,
                'last_used_api_base' => $r['last_used_api_base'] !== null ? (string) $r['last_used_api_base'] : null,
                'revoked_at' => $r['revoked_at'] !== null ? (string) $r['revoked_at'] : null,
            ];
        }
        return $out;
    }

    /** 32 random bytes, base64url, unpadded — 43 characters, no shell-hostile ones. */
    private static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Site ids reach the key text, so keep them to characters that survive a copy-paste. */
    private static function slug(string $site): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $site) ?? '');
        $slug = trim($slug, '-');
        return $slug === '' ? 'site' : substr($slug, 0, 24);
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        // `id` carries an explicit NOT NULL: Phinx-free hand-written DDL or not,
        // MySQL 8 rejects a nullable PRIMARY KEY column outright (SQLSTATE 1171)
        // while MariaDB silently coerces it — and the prod host is the MySQL.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_site_key (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                site VARCHAR(64) NOT NULL,
                label VARCHAR(120) NOT NULL DEFAULT \'\',
                origin VARCHAR(191) NOT NULL DEFAULT \'\',
                key_prefix VARCHAR(24) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                last_used_origin VARCHAR(191) NULL,
                last_used_api_base VARCHAR(191) NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_site_key_hash (key_hash),
                KEY idx_site_key_site (site)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }

    /** Test seam — the schema flag is process-wide, like SettingsStore's. */
    public static function resetSchemaFlagForTests(): void
    {
        self::$schemaEnsured = false;
    }
}
