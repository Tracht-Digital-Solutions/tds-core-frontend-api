<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use DI\Definition\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Bootstrap;

/**
 * Every service a composed module binds into the container must actually be
 * buildable from the booted app.
 *
 * WHY THIS EXISTS. Both CMS modules guarded their bindings with
 * `if (!$c->has(X::class)) { $c->set(X::class, …); }`. PHP-DI answers `has()`
 * from its definition sources and **autowiring is one of them**, so for any
 * concrete, instantiable class the answer is always `true` — bound or not. The
 * guard therefore skipped every binding it protected and the container silently
 * autowired instead.
 *
 * For a repository that is invisible: its only constructor argument is the
 * bound `PDO`, so autowiring produces an identical object. For a service whose
 * constructor takes a **string** it is fatal, and only on the routes that
 * resolve it:
 *
 *     Parameter $apiKey of __construct() has no value defined or guessable
 *
 * Reading the CMS worked, saving a post or a content block answered 500, and
 * the settings-store factories (DeepL key, rebuild PAT) had never run once.
 * Nothing else looks: these extension repos have no PHP suite in CI, the
 * composition test only checks that routes are *mounted*, and a container entry
 * is built lazily — so a broken binding costs nothing until someone saves.
 *
 * The list is read out of each composed module's own source rather than
 * hard-coded here, so a new module or a new binding joins the check by existing.
 */
final class ExtensionBindingsTest extends TestCase
{
    public function testEveryModuleBindingResolves(): void
    {
        $container = Bootstrap::createApp(dirname(__DIR__))->getContainer();

        $bindings = self::declaredBindings();
        self::assertNotEmpty($bindings, 'no module sources found — the vendor layout changed');

        // With a database present the factories run for real, which is the
        // stronger check. Without one, probe the connection ONCE and then rebind
        // PDO to something that fails instantly: nearly every binding pulls a
        // repository, so leaving the real one in place pays a full TCP timeout
        // per entry and turns a sub-second check into a minute of waiting.
        try {
            $container->get(\PDO::class);
        } catch (\Throwable) {
            $container->set(\PDO::class, static function (): \PDO {
                throw new \RuntimeException('no database configured for this check');
            });
        }

        foreach ($bindings as $fqcn => $source) {
            try {
                $container->get($fqcn);
            } catch (InvalidDefinition $e) {
                // The one failure this test is about: PHP-DI had no definition
                // it could build — i.e. the module's own set() never landed and
                // autowiring could not guess a constructor argument.
                self::fail(sprintf(
                    "%s binds %s, but it cannot be resolved:\n  %s\n"
                    . "  (a `!\$c->has(X::class)` guard around the set() is the usual cause — "
                    . "PHP-DI says yes for every instantiable class, bound or not)",
                    $source,
                    $fqcn,
                    $e->getMessage(),
                ));
            } catch (\Throwable) {
                // Anything else — no database reachable, no third-party service —
                // is environmental. Ignoring it is what keeps this check runnable
                // with no MariaDB, unlike the DB-backed suites: a binding defect
                // must not need a database to be visible.
            }
        }
    }

    /**
     * `$c->set(Foo::class, …)` occurrences in every composed module, resolved to
     * FQCNs through that file's `use` imports.
     *
     * @return array<class-string, string> fqcn => module file basename
     */
    private static function declaredBindings(): array
    {
        $out = [];
        $modules = glob(dirname(__DIR__) . '/vendor/tracht-digital-solutions/*/php/src/*Module.php') ?: [];

        foreach ($modules as $file) {
            $code = (string) file_get_contents($file);

            /** @var array<string, class-string> $imports short name => FQCN */
            $imports = [];
            if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $use) {
                    $fqcn = $use[1];
                    $alias = $use[2] ?? substr((string) strrchr('\\' . $fqcn, '\\'), 1);
                    $imports[$alias] = $fqcn;
                }
            }

            if (!preg_match_all('/\$c->set\(\s*([\w\\\\]+)::class/', $code, $sets)) {
                continue;
            }
            foreach ($sets[1] as $name) {
                $fqcn = $imports[$name] ?? $name;
                if (class_exists($fqcn) || interface_exists($fqcn)) {
                    $out[$fqcn] = basename($file);
                }
            }
        }

        return $out;
    }
}
