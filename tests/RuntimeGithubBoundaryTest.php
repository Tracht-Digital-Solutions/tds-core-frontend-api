<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;

/** The running API must not query registries or dispatch repository workflows. */
final class RuntimeGithubBoundaryTest extends TestCase
{
    public function testRuntimeUpdateServicesAreAbsent(): void
    {
        $root = dirname(__DIR__);
        foreach (['AutoUpdater', 'ModuleUpdateConfig', 'PackageRegistry', 'VersionRange', 'WorkflowDispatcher'] as $class) {
            self::assertFileDoesNotExist($root . '/src/Service/' . $class . '.php');
        }
    }

    public function testRemovedRuntimeRoutesDoNotReturnToBootstrap(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Bootstrap.php');
        foreach (['/admin/modules/check', '/admin/modules/auto-update', '/admin/modules/deploy'] as $route) {
            self::assertStringNotContainsString($route, $source);
        }
        self::assertStringContainsString("'/admin/modules'", $source);
    }
}
