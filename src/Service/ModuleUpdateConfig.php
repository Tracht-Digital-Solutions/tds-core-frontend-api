<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * Resolved configuration for the panel's Module page: which registry to ask for
 * published versions, and which pipelines "aktualisieren" may start.
 *
 * Read **DB-first with an env fallback**, the platform's standard pattern for
 * third-party config (`SettingsStore` namespace `modules`, editable under
 * Einstellungen → *Module & Deployment*). Secrets are stored encrypted; the env
 * fallback keeps a `.env`-only host working before anyone opens the form.
 *
 * Never throws. A host without a database — the state the frontend service is
 * in until `services/frontend/.env` + `tds_frontend` exist — resolves to the
 * coded defaults with no tokens, so the page renders "nicht konfiguriert"
 * instead of a 500. That is the whole reason this reads through a try/catch:
 * the Module page must be usable precisely when deployment is not yet healthy.
 */
final class ModuleUpdateConfig
{
    public const NAMESPACE = 'modules';

    /** Automation switches, read by {@see AutoUpdater} straight from the store. */
    public const KEY_AUTO_UPDATE = 'auto_update';
    public const KEY_AUTO_INTERVAL = 'auto_update_interval';

    /**
     * Declared keys, in form order: [key, secret, env fallback, default].
     * Also what the admin settings form renders, so a new key needs one edit.
     *
     * @var list<array{0:string,1:bool,2:string,3:string}>
     */
    private const KEYS = [
        ['registry_token', true, 'MODULES_REGISTRY_TOKEN', ''],
        ['dispatch_token', true, 'MODULES_DISPATCH_TOKEN', ''],
        ['frontend_repo', false, 'MODULES_FRONTEND_REPO', ''],
        ['frontend_workflow', false, 'MODULES_FRONTEND_WORKFLOW', 'release.yml'],
        ['backend_repo', false, 'MODULES_BACKEND_REPO', 'Tracht-Digital-Solutions/tds-gateway-api'],
        ['backend_workflow', false, 'MODULES_BACKEND_WORKFLOW', 'release.yml'],
        ['ref', false, 'MODULES_REF', 'main'],
        [self::KEY_AUTO_UPDATE, false, 'MODULES_AUTO_UPDATE', '0'],
        [self::KEY_AUTO_INTERVAL, false, 'MODULES_AUTO_UPDATE_INTERVAL', '24'],
    ];

    private function __construct(
        public readonly string $registryToken,
        public readonly string $dispatchToken,
        public readonly string $frontendRepo,
        public readonly string $frontendWorkflow,
        public readonly string $backendRepo,
        public readonly string $backendWorkflow,
        public readonly string $ref,
        /** Unattended updates enabled? {@see AutoUpdater} */
        public readonly bool $autoUpdate,
        /** Check interval in hours, clamped by the updater. */
        public readonly int $autoIntervalHours,
    ) {
    }

    /** @param callable(string,?string):string $env The Bootstrap env reader. */
    public static function resolve(?SettingsStoreContract $store, callable $env): self
    {
        $read = static function (string $key, bool $secret, string $envKey, string $default) use ($store, $env): string {
            $stored = null;
            try {
                $stored = $secret ? $store?->getSecret(self::NAMESPACE, $key) : $store?->get(self::NAMESPACE, $key);
            } catch (\Throwable) {
                // No DB yet (or an undecryptable value) — fall through to env.
                $stored = null;
            }
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
            return (string) $env($envKey, $default);
        };

        $values = [];
        foreach (self::KEYS as [$key, $secret, $envKey, $default]) {
            $values[$key] = $read($key, $secret, $envKey, $default);
        }

        return new self(
            registryToken: $values['registry_token'],
            // One PAT usually carries both scopes; falling back keeps the common
            // case to a single field instead of asking for the same token twice.
            dispatchToken: $values['dispatch_token'] !== '' ? $values['dispatch_token'] : $values['registry_token'],
            frontendRepo: $values['frontend_repo'],
            frontendWorkflow: $values['frontend_workflow'],
            backendRepo: $values['backend_repo'],
            backendWorkflow: $values['backend_workflow'],
            ref: $values['ref'],
            // Anything but a literal "1" is off. An automation that could be
            // switched on by a typo is worse than one that needs an exact value.
            autoUpdate: $values[self::KEY_AUTO_UPDATE] === '1',
            autoIntervalHours: (int) $values[self::KEY_AUTO_INTERVAL],
        );
    }

    /**
     * The two deploy targets as the panel renders them. `configured` means the
     * button may be pressed — a repo AND a token.
     *
     * @return list<array{key:string,label:string,repo:string,workflow:string,configured:bool}>
     */
    public function targets(): array
    {
        return [
            [
                'key' => 'frontend',
                'label' => 'Frontend neu bauen',
                'repo' => $this->frontendRepo,
                'workflow' => $this->frontendWorkflow,
                'configured' => $this->frontendRepo !== '' && $this->dispatchToken !== '',
            ],
            [
                'key' => 'backend',
                'label' => 'Backend neu ausliefern',
                'repo' => $this->backendRepo,
                'workflow' => $this->backendWorkflow,
                'configured' => $this->backendRepo !== '' && $this->dispatchToken !== '',
            ],
        ];
    }

    /**
     * Repo + workflow for a target key, or null when the key is unknown or the
     * target is not configured.
     *
     * @return array{repo:string,workflow:string}|null
     */
    public function target(string $key): ?array
    {
        foreach ($this->targets() as $t) {
            if ($t['key'] === $key && $t['configured']) {
                return ['repo' => $t['repo'], 'workflow' => $t['workflow']];
            }
        }
        return null;
    }

    /** The declared setting keys, for the admin form. @return list<array{key:string,secret:bool}> */
    public static function declaredKeys(): array
    {
        return array_map(
            static fn (array $k): array => ['key' => $k[0], 'secret' => $k[1]],
            self::KEYS,
        );
    }
}
