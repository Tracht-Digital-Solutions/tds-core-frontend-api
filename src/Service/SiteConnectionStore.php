<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use PDO;
use Tds\Frontend\Contract\SiteConnection;

/** Persistence for paired CMS resources and their short-lived invitations. */
final class SiteConnectionStore
{
    private static bool $schemaEnsured = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionKey,
    ) {
    }

    public function encryptionConfigured(): bool
    {
        return trim($this->encryptionKey) !== '';
    }

    public function get(string $resourceType, string $resourceId): ?SiteConnection
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_site_connection
              WHERE resource_type = :resource_type AND resource_id = :resource_id LIMIT 1'
        );
        $stmt->execute([':resource_type' => $resourceType, ':resource_id' => $resourceId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::connection($row);
    }

    /** @return array{origin:string,token:string}|null */
    public function cacheTarget(string $resourceType, string $resourceId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT origin, cache_token_enc FROM app_site_connection
              WHERE resource_type = :resource_type AND resource_id = :resource_id
                AND status = :status LIMIT 1'
        );
        $stmt->execute([
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':status' => SiteConnection::CONNECTED,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $token = SettingsStore::decrypt((string) $row['cache_token_enc'], $this->encryptionKey);
        return $token === null || $token === '' ? null : ['origin' => (string) $row['origin'], 'token' => $token];
    }

    public function delete(string $resourceType, string $resourceId, SiteKeyStore $keys): bool
    {
        return $this->transaction(function () use ($resourceType, $resourceId, $keys): bool {
            // Deleting a connection is also a revocation of every invitation
            // that could recreate it. Without this, an already exchanged but
            // not yet finalised pairing could resurrect the connection after
            // the operator explicitly disconnected the site.
            $this->cancelPending($resourceType, $resourceId, $keys);
            $current = $this->get($resourceType, $resourceId);
            if ($current === null) {
                return false;
            }
            $stmt = $this->pdo->prepare(
                'DELETE FROM app_site_connection WHERE resource_type = :resource_type AND resource_id = :resource_id'
            );
            $stmt->execute([':resource_type' => $resourceType, ':resource_id' => $resourceId]);
            if ($current->siteKeyId !== null) {
                $keys->revoke($current->siteKeyId);
            }
            return $stmt->rowCount() > 0;
        });
    }

    /** @param array<string,mixed> $bindings @param list<string> $scopes */
    public function insertPairing(
        string $publicId,
        string $tokenHash,
        string $resourceType,
        string $resourceId,
        string $origin,
        string $profile,
        array $bindings,
        array $scopes,
        string $expiresAt,
    ): void {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_site_pairing
                (public_id, pairing_hash, resource_type, resource_id, origin, profile,
                 bindings_json, scopes_json, expires_at, created_at)
             VALUES
                (:public_id, :pairing_hash, :resource_type, :resource_id, :origin, :profile,
                 :bindings, :scopes, :expires_at, NOW())'
        );
        $stmt->execute([
            ':public_id' => $publicId,
            ':pairing_hash' => $tokenHash,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':origin' => $origin,
            ':profile' => $profile,
            ':bindings' => self::encodeObject($bindings),
            ':scopes' => self::encodeList($scopes),
            ':expires_at' => $expiresAt,
        ]);
    }

    /** Revoke abandoned pending keys while leaving the active connection untouched. */
    public function cancelPending(string $resourceType, string $resourceId, SiteKeyStore $keys): void
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT id, pending_site_key_id FROM app_site_pairing
              WHERE resource_type = :resource_type AND resource_id = :resource_id
                AND finalized_at IS NULL AND cancelled_at IS NULL FOR UPDATE'
        );
        $stmt->execute([':resource_type' => $resourceType, ':resource_id' => $resourceId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['id'];
            if ($row['pending_site_key_id'] !== null) {
                $keys->revoke((int) $row['pending_site_key_id']);
            }
        }
        if ($ids === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE app_site_pairing SET cancelled_at = NOW() WHERE id IN ({$marks})")
            ->execute($ids);
    }

    /** @param array<string,mixed> $pairing */
    public function cancelPairing(array $pairing, SiteKeyStore $keys): void
    {
        if ($pairing['pending_site_key_id'] !== null) {
            $keys->revoke((int) $pairing['pending_site_key_id']);
        }
        $this->pdo->prepare('UPDATE app_site_pairing SET cancelled_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $pairing['id']]);
    }

    /** @return array<string,mixed>|null */
    public function pairingByTokenHash(string $hash, bool $forUpdate = false): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_site_pairing WHERE pairing_hash = :hash LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Bind an invitation to the canonical API origin used by the authenticated
     * CMS request before the token is sent to the public site.
     */
    public function pinApiBase(string $publicId, string $apiBase): void
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'UPDATE app_site_pairing
                SET api_base = :api_base
              WHERE public_id = :public_id AND api_base IS NULL
                AND exchanged_at IS NULL AND finalized_at IS NULL AND cancelled_at IS NULL'
        );
        $stmt->execute([':api_base' => $apiBase, ':public_id' => $publicId]);

        $check = $this->pdo->prepare('SELECT api_base FROM app_site_pairing WHERE public_id = :public_id LIMIT 1');
        $check->execute([':public_id' => $publicId]);
        $stored = $check->fetchColumn();
        if (!is_string($stored) || !hash_equals($stored, $apiBase)) {
            throw new SitePairingException('API-Origin des Pairings konnte nicht gebunden werden.', 409, 'pairing_api_mismatch');
        }
    }

    /** @return array<string,mixed>|null */
    public function pairingByPublicId(string $publicId, bool $forUpdate = false): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_site_pairing WHERE public_id = :public_id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':public_id' => $publicId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markExchanged(
        int $id,
        string $finalizeHash,
        int $siteKeyId,
        string $cacheToken,
        string $apiBase,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE app_site_pairing
                SET finalize_hash = :finalize_hash,
                    pending_site_key_id = :site_key_id,
                    pending_cache_token_enc = :cache_token,
                    api_base = :api_base,
                    exchanged_at = NOW()
              WHERE id = :id AND exchanged_at IS NULL'
        );
        $stmt->execute([
            ':finalize_hash' => $finalizeHash,
            ':site_key_id' => $siteKeyId,
            ':cache_token' => SettingsStore::encrypt($cacheToken, $this->encryptionKey),
            ':api_base' => $apiBase,
            ':id' => $id,
        ]);
    }

    public function finalize(array $pairing, SiteKeyStore $keys): SiteConnection
    {
        $old = $this->get((string) $pairing['resource_type'], (string) $pairing['resource_id']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_site_connection
                (resource_type, resource_id, origin, profile, bindings_json, scopes_json,
                 site_key_id, cache_token_enc, status, paired_at, created_at, updated_at)
             VALUES
                (:resource_type, :resource_id, :origin, :profile, :bindings, :scopes,
                 :site_key_id, :cache_token, :status, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id), origin = :origin2, profile = :profile2,
                bindings_json = :bindings2, scopes_json = :scopes2,
                site_key_id = :site_key_id2, cache_token_enc = :cache_token2,
                status = :status2, paired_at = NOW(), updated_at = NOW()'
        );
        $values = [
            ':resource_type' => (string) $pairing['resource_type'],
            ':resource_id' => (string) $pairing['resource_id'],
            ':origin' => (string) $pairing['origin'],
            ':profile' => (string) $pairing['profile'],
            ':bindings' => (string) $pairing['bindings_json'],
            ':scopes' => (string) $pairing['scopes_json'],
            ':site_key_id' => (int) $pairing['pending_site_key_id'],
            ':cache_token' => (string) $pairing['pending_cache_token_enc'],
            ':status' => SiteConnection::CONNECTED,
        ];
        $stmt->execute($values + [
            ':origin2' => $values[':origin'], ':profile2' => $values[':profile'],
            ':bindings2' => $values[':bindings'], ':scopes2' => $values[':scopes'],
            ':site_key_id2' => $values[':site_key_id'], ':cache_token2' => $values[':cache_token'],
            ':status2' => $values[':status'],
        ]);
        $connectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'UPDATE app_site_pairing SET finalized_at = NOW(), connection_id = :connection_id WHERE id = :id'
        )->execute([':connection_id' => $connectionId, ':id' => (int) $pairing['id']]);

        if ($old?->siteKeyId !== null && $old->siteKeyId !== (int) $pairing['pending_site_key_id']) {
            $keys->revoke($old->siteKeyId);
        }
        $connection = $this->get((string) $pairing['resource_type'], (string) $pairing['resource_id']);
        if ($connection === null) {
            throw new \RuntimeException('connection finalization did not persist');
        }
        return $connection;
    }

    /** @template T @param callable():T $callback @return T */
    public function transaction(callable $callback): mixed
    {
        $owned = !$this->pdo->inTransaction();
        if ($owned) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owned) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owned && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function bindings(array $pairing): array
    {
        return self::decodeObject((string) ($pairing['bindings_json'] ?? ''));
    }

    /** @return list<string> */
    public static function scopes(array $pairing): array
    {
        return array_values(array_map('strval', self::decodeObject((string) ($pairing['scopes_json'] ?? ''))));
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_site_connection (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_type VARCHAR(32) NOT NULL,
                resource_id VARCHAR(191) NOT NULL,
                origin VARCHAR(191) NOT NULL,
                profile VARCHAR(32) NOT NULL,
                bindings_json TEXT NULL,
                scopes_json TEXT NULL,
                site_key_id INT UNSIGNED NULL,
                cache_token_enc TEXT NULL,
                status VARCHAR(24) NOT NULL DEFAULT \'needs_pairing\',
                paired_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_site_connection_resource (resource_type, resource_id),
                KEY idx_site_connection_key (site_key_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_site_pairing (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id VARCHAR(64) NOT NULL,
                pairing_hash CHAR(64) NOT NULL,
                resource_type VARCHAR(32) NOT NULL,
                resource_id VARCHAR(191) NOT NULL,
                origin VARCHAR(191) NOT NULL,
                profile VARCHAR(32) NOT NULL,
                bindings_json TEXT NULL,
                scopes_json TEXT NULL,
                expires_at DATETIME NOT NULL,
                finalize_hash CHAR(64) NULL,
                pending_site_key_id INT UNSIGNED NULL,
                pending_cache_token_enc TEXT NULL,
                api_base VARCHAR(191) NULL,
                exchanged_at DATETIME NULL,
                finalized_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                connection_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_site_pairing_public (public_id),
                UNIQUE KEY uniq_site_pairing_hash (pairing_hash),
                KEY idx_site_pairing_resource (resource_type, resource_id),
                KEY idx_site_pairing_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }

    /** @param array<string,mixed> $row */
    private static function connection(array $row): SiteConnection
    {
        return new SiteConnection(
            (int) $row['id'],
            (string) $row['resource_type'],
            (string) $row['resource_id'],
            (string) $row['origin'],
            (string) $row['profile'],
            self::decodeObject((string) ($row['bindings_json'] ?? '')),
            array_values(array_map('strval', self::decodeObject((string) ($row['scopes_json'] ?? '')))),
            (string) $row['status'],
            $row['site_key_id'] !== null ? (int) $row['site_key_id'] : null,
            $row['paired_at'] !== null ? (string) $row['paired_at'] : null,
            $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
        );
    }

    /** @param array<string,mixed> $value */
    private static function encodeObject(array $value): string
    {
        return json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $value */
    private static function encodeList(array $value): string
    {
        return json_encode(array_values($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function resetSchemaFlagForTests(): void
    {
        self::$schemaEnsured = false;
    }
}
