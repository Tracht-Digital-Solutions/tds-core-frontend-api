<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Domain;

use PDO;

/**
 * Per-user interface preferences: theme, locale, notification toggles.
 *
 * A base-service table (owned by the core, not an extension) keyed by the panel
 * user id × preference key. Sibling of {@see DashboardLayoutRepository}, and
 * self-bootstrapping for the same reason: the core has no Phinx migrator wired
 * for BASE tables (extensions migrate through `Support\MigrationRunner`), so an
 * idempotent `CREATE TABLE IF NOT EXISTS` runs once per process. When the core
 * gains one, move this DDL into a migration and drop `ensureSchema()`.
 *
 * ### Why key/value and not columns
 *
 * A column per preference means a migration per preference, and the core has no
 * migrator — every new toggle would have to go through the self-bootstrapping
 * DDL, which cannot ALTER an existing table. Rows also make an unknown key
 * (written by a newer panel against an older backend) inert rather than fatal.
 *
 * ### Values are strings, and the whitelist lives at the route
 *
 * Everything stored here is a short scalar the frontend renders back into a
 * control. The **route** validates keys and values against a whitelist before
 * calling {@see self::setMany()} — this class deliberately does not know what
 * "theme" means, so adding a preference is a one-line change there.
 */
final class UserPreferenceRepository
{
    private static bool $schemaEnsured = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * All stored preferences for one user, as `key => value`.
     *
     * @return array<string,string>
     */
    public function all(int $userId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT pkey, pvalue FROM user_preference WHERE user_id = :u ORDER BY pkey'
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['pkey']] = (string) ($row['pvalue'] ?? '');
        }
        return $out;
    }

    /**
     * Upsert the given preferences. A PARTIAL write: keys not mentioned are
     * left alone, so the Darstellung tab saving a theme cannot clear the
     * notification toggles it never rendered.
     *
     * @param array<string,string> $values
     */
    public function setMany(int $userId, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->ensureSchema();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_preference (user_id, pkey, pvalue) VALUES (:u, :k, :v)
                 ON DUPLICATE KEY UPDATE pvalue = VALUES(pvalue)'
            );
            foreach ($values as $key => $value) {
                $stmt->execute([':u' => $userId, ':k' => $key, ':v' => $value]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_preference (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                pkey VARCHAR(64) NOT NULL,
                pvalue TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_pref (user_id, pkey),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }
}
