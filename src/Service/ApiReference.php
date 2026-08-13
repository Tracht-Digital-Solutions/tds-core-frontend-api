<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Slim\App;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\Module;
use Tds\Frontend\Contract\ModuleRegistry;

/**
 * Builds the admin frontend's API reference (`GET /wiki.json`) — the base plus
 * every composed module, grouped by the module that owns each route.
 *
 * ### Introspection is the list; docs are the prose
 *
 * The route set comes from Slim's `RouteCollector` after composition, so it is
 * complete by construction: a module route that nobody documented still shows
 * up (`documented: false`), and no one can shrink the reference by forgetting
 * to write something down. What introspection cannot recover — purpose,
 * parameters, responses, required permission — is contributed by the modules
 * through {@see ApiDocSource} and joined on `"<METHOD> <pattern>"`.
 *
 * The reverse mismatch is reported too: a doc entry with no matching route
 * means a path was renamed and the prose was left behind, which is exactly the
 * failure a reference full of confident, wrong detail would otherwise hide. It
 * lands in `stats.orphan_docs` rather than being dropped.
 *
 * ### Grouping is ownership, not the path
 *
 * `ModuleRegistry::routeOwners()` records who mounted what during composition.
 * The previous version grouped by first path segment, which put all thirteen
 * modules' `/admin/*` routes into one bucket called `admin` — the single thing
 * that made the old page unusable as a reference.
 *
 * Modules are emitted by **id** only. The German display name lives in each
 * extension's TS manifest, which the frontend already has composed into
 * `virtual:frontend-registry`; duplicating it here would be a second source of
 * truth that nothing keeps in sync.
 */
final class ApiReference
{
    /** Payload shape version — the frontend renders v2 and refuses anything else. */
    public const VERSION = 2;

    /** Group id used for routes the base kernel mounted itself. */
    public const BASE = 'base';

    public function __construct(
        private readonly App $app,
        private readonly ModuleRegistry $registry,
    ) {
    }

    /**
     * @return array{
     *   generated_at: string,
     *   version: int,
     *   modules: list<array{id: string, routes: list<array<string, mixed>>}>,
     *   stats: array{routes: int, documented: int, modules: int, orphan_docs: list<string>}
     * }
     */
    public function build(): array
    {
        $owners = $this->registry->routeOwners();
        $docs = $this->collectDocs();

        /** @var array<string, list<array<string, mixed>>> $byModule */
        $byModule = [];
        $documented = 0;
        $matched = [];

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            foreach ($route->getMethods() as $method) {
                // HEAD and OPTIONS are added by Slim/the CORS layer, not by
                // anyone's design — listing them would triple the reference
                // with rows nobody wrote or calls.
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $key = strtoupper($method) . ' ' . $pattern;
                $moduleId = $owners[$key] ?? self::BASE;
                $doc = $docs[$key] ?? null;
                if ($doc !== null) {
                    $documented++;
                    $matched[$key] = true;
                }
                $byModule[$moduleId][] = self::entry($method, $pattern, $doc);
            }
        }

        // Base first (it is the kernel everything else mounts onto), then the
        // modules in composition order — the same order the registry loaded
        // them, so the page reads like the build.
        $ids = array_values(array_filter(
            array_merge([self::BASE], $this->registry->order()),
            static fn (string $id): bool => isset($byModule[$id]),
        ));

        $modules = [];
        $total = 0;
        foreach ($ids as $id) {
            $routes = $byModule[$id];
            usort($routes, static fn (array $a, array $b): int =>
                [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']]);
            $modules[] = ['id' => $id, 'routes' => array_values($routes)];
            $total += count($routes);
        }

        $orphans = array_values(array_diff(array_keys($docs), array_keys($matched)));
        sort($orphans);

        return [
            'generated_at' => date('c'),
            'version' => self::VERSION,
            'modules' => $modules,
            'stats' => [
                'routes' => $total,
                'documented' => $documented,
                'modules' => count($modules),
                'orphan_docs' => $orphans,
            ],
        ];
    }

    /**
     * Every module's docs plus the base kernel's own, keyed by "<METHOD> <pattern>".
     *
     * A source that throws loses only its own prose — its routes still appear,
     * undocumented. Same reasoning as {@see NotificationFeed}: one careless
     * module must not take the whole page down.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectDocs(): array
    {
        $out = [];
        foreach (self::baseDocs() as $doc) {
            $out[strtoupper((string) $doc['method']) . ' ' . $doc['pattern']] = $doc;
        }
        foreach ($this->registry->apiDocSources() as $source) {
            try {
                $entries = $source->apiDocs();
            } catch (\Throwable) {
                continue;
            }
            foreach ($entries as $doc) {
                if (!is_array($doc) || !isset($doc['method'], $doc['pattern'])) {
                    continue;
                }
                $key = strtoupper((string) $doc['method']) . ' ' . $doc['pattern'];
                // First writer wins, and the base cannot be overwritten by a
                // module claiming one of its routes.
                $out[$key] ??= $doc;
            }
        }
        return $out;
    }

    /**
     * Normalise one route + its optional doc into the wire entry.
     *
     * @param array<string, mixed>|null $doc
     * @return array<string, mixed>
     */
    private static function entry(string $method, string $pattern, ?array $doc): array
    {
        $permission = isset($doc['permission']) ? (string) $doc['permission'] : null;
        $entry = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'documented' => $doc !== null,
            'summary' => isset($doc['summary']) ? (string) $doc['summary'] : '',
            // Defaulting `auth` from `permission` keeps the doc arrays terse:
            // stating the permission already says how the route is gated.
            'auth' => isset($doc['auth'])
                ? (string) $doc['auth']
                : ($permission !== null ? 'permission' : 'public'),
        ];
        if ($doc === null) {
            // Nothing is claimed about an undocumented route — not even that it
            // is public. The frontend shows the bare row and says so.
            unset($entry['auth']);
            return $entry;
        }
        foreach (['description', 'tag'] as $key) {
            if (isset($doc[$key]) && (string) $doc[$key] !== '') {
                $entry[$key] = (string) $doc[$key];
            }
        }
        if ($permission !== null) {
            $entry['permission'] = $permission;
        }
        if (isset($doc['params']) && is_array($doc['params']) && $doc['params'] !== []) {
            $entry['params'] = array_values($doc['params']);
        }
        if (isset($doc['responses']) && is_array($doc['responses']) && $doc['responses'] !== []) {
            $entry['responses'] = array_values($doc['responses']);
        }
        return $entry;
    }

    /**
     * The base kernel's own routes. It is not a {@see Module}, so it cannot
     * implement {@see ApiDocSource} — but its eleven routes are the ones every
     * frontend depends on, so they are the last place that should be blank.
     *
     * @return list<array<string, mixed>>
     */
    public static function baseDocs(): array
    {
        return require __DIR__ . '/../../docs/api.php';
    }
}
