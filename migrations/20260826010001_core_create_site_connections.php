<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Adds resource-bound keys plus the two-phase public-site connection store. */
final class CoreCreateSiteConnections extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('app_site_key')) {
            $this->table('app_site_key', [
                'id' => false,
                'primary_key' => 'id',
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'integer', ['signed' => false, 'identity' => true])
                ->addColumn('site', 'string', ['limit' => 64])
                ->addColumn('label', 'string', ['limit' => 120, 'default' => ''])
                ->addColumn('origin', 'string', ['limit' => 191, 'default' => ''])
                ->addColumn('key_prefix', 'string', ['limit' => 24])
                ->addColumn('key_hash', 'string', ['limit' => 64])
                ->addColumn('created_at', 'datetime')
                ->addColumn('last_used_at', 'datetime', ['null' => true])
                ->addColumn('last_used_origin', 'string', ['limit' => 191, 'null' => true])
                ->addColumn('last_used_api_base', 'string', ['limit' => 191, 'null' => true])
                ->addColumn('revoked_at', 'datetime', ['null' => true])
                ->addIndex(['key_hash'], ['unique' => true, 'name' => 'uniq_site_key_hash'])
                ->addIndex(['site'], ['name' => 'idx_site_key_site'])
                ->create();
        }

        $keys = $this->table('app_site_key');
        foreach ([
            'resource_type' => ['string', ['limit' => 32, 'null' => true]],
            'resource_id' => ['string', ['limit' => 191, 'null' => true]],
            'bindings_json' => ['text', ['null' => true]],
            'scopes_json' => ['text', ['null' => true]],
        ] as $name => [$type, $options]) {
            if (!$keys->hasColumn($name)) {
                $keys->addColumn($name, $type, $options);
            }
        }
        $keys->update();
        // The historical spelling never matched SiteKeyPolicy's canonical id.
        $this->execute("UPDATE app_site_key SET site = 'landingpage' WHERE site = 'landing'");

        if (!$this->hasTable('app_site_connection')) {
            $this->table('app_site_connection', [
                'id' => false,
                'primary_key' => 'id',
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
                ->addColumn('resource_type', 'string', ['limit' => 32])
                ->addColumn('resource_id', 'string', ['limit' => 191])
                ->addColumn('origin', 'string', ['limit' => 191])
                ->addColumn('profile', 'string', ['limit' => 32])
                ->addColumn('bindings_json', 'text', ['null' => true])
                ->addColumn('scopes_json', 'text', ['null' => true])
                ->addColumn('site_key_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('cache_token_enc', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'needs_pairing'])
                ->addColumn('paired_at', 'datetime', ['null' => true])
                ->addColumn('last_seen_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['resource_type', 'resource_id'], ['unique' => true, 'name' => 'uniq_site_connection_resource'])
                ->addIndex(['site_key_id'], ['name' => 'idx_site_connection_key'])
                ->create();
        }

        if (!$this->hasTable('app_site_pairing')) {
            $this->table('app_site_pairing', [
                'id' => false,
                'primary_key' => 'id',
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
                ->addColumn('public_id', 'string', ['limit' => 64])
                ->addColumn('pairing_hash', 'string', ['limit' => 64])
                ->addColumn('resource_type', 'string', ['limit' => 32])
                ->addColumn('resource_id', 'string', ['limit' => 191])
                ->addColumn('origin', 'string', ['limit' => 191])
                ->addColumn('profile', 'string', ['limit' => 32])
                ->addColumn('bindings_json', 'text', ['null' => true])
                ->addColumn('scopes_json', 'text', ['null' => true])
                ->addColumn('expires_at', 'datetime')
                ->addColumn('finalize_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('pending_site_key_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('pending_cache_token_enc', 'text', ['null' => true])
                ->addColumn('api_base', 'string', ['limit' => 191, 'null' => true])
                ->addColumn('exchanged_at', 'datetime', ['null' => true])
                ->addColumn('finalized_at', 'datetime', ['null' => true])
                ->addColumn('cancelled_at', 'datetime', ['null' => true])
                ->addColumn('connection_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['public_id'], ['unique' => true, 'name' => 'uniq_site_pairing_public'])
                ->addIndex(['pairing_hash'], ['unique' => true, 'name' => 'uniq_site_pairing_hash'])
                ->addIndex(['resource_type', 'resource_id'], ['name' => 'idx_site_pairing_resource'])
                ->addIndex(['expires_at'], ['name' => 'idx_site_pairing_expiry'])
                ->create();
        }

        $this->importLegacyOrigins();
    }

    public function down(): void
    {
        if ($this->hasTable('app_site_pairing')) {
            $this->table('app_site_pairing')->drop()->save();
        }
        if ($this->hasTable('app_site_connection')) {
            $this->table('app_site_connection')->drop()->save();
        }
        if ($this->hasTable('app_site_key')) {
            $keys = $this->table('app_site_key');
            foreach (['resource_type', 'resource_id', 'bindings_json', 'scopes_json'] as $column) {
                if ($keys->hasColumn($column)) {
                    $keys->removeColumn($column);
                }
            }
            $keys->update();
        }
    }

    private function importLegacyOrigins(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        if ($this->hasTable('blog') && $this->table('blog')->hasColumn('cache_url')) {
            foreach ($pdo->query('SELECT blog_key AS rid, cache_url AS origin FROM blog WHERE cache_url IS NOT NULL')->fetchAll() as $row) {
                $this->insertLegacy($pdo, 'blog', (string) $row['rid'], (string) $row['origin'], 'blog');
            }
        }
        if ($this->hasTable('cms_site') && $this->table('cms_site')->hasColumn('cache_url')) {
            foreach ($pdo->query('SELECT site_key AS rid, cache_url AS origin FROM cms_site WHERE cache_url IS NOT NULL')->fetchAll() as $row) {
                $this->insertLegacy($pdo, 'website', (string) $row['rid'], (string) $row['origin'], 'landingpage');
            }
        }
        if ($this->hasTable('app_setting')) {
            $stmt = $pdo->query("SELECT svalue FROM app_setting WHERE namespace = 'tools' AND skey = 'cache_url' AND is_secret = 0 LIMIT 1");
            $row = $stmt->fetch();
            if (is_array($row)) {
                $this->insertLegacy($pdo, 'tools', 'tools', (string) ($row['svalue'] ?? ''), 'tools');
            }
        }
    }

    private function insertLegacy(PDO $pdo, string $type, string $id, string $origin, string $profile): void
    {
        $origin = self::normaliseOrigin($origin);
        if ($id === '' || $origin === null) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO app_site_connection
                (resource_type, resource_id, origin, profile, bindings_json, scopes_json,
                 status, created_at, updated_at)
             VALUES (:type, :id, :origin, :profile, :bindings, :scopes, :status, NOW(), NOW())'
        );
        $stmt->execute([
            ':type' => $type,
            ':id' => $id,
            ':origin' => $origin,
            ':profile' => $profile,
            ':bindings' => json_encode((object) [$type => $id], JSON_THROW_ON_ERROR),
            ':scopes' => json_encode([], JSON_THROW_ON_ERROR),
            ':status' => 'needs_pairing',
        ]);
    }

    private static function normaliseOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && preg_match('#^/+$#', $path) !== 1) {
            return null;
        }
        return 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
