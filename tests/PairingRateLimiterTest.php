<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\PairingRateLimiter;

final class PairingRateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tds-pairing-limit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testRejectsAfterTheLimitWithinAWindow(): void
    {
        $limiter = new PairingRateLimiter($this->directory);
        self::assertTrue($limiter->allow('exchange:site', 2, 60, 100));
        self::assertTrue($limiter->allow('exchange:site', 2, 60, 101));
        self::assertFalse($limiter->allow('exchange:site', 2, 60, 102));
    }

    public function testOldAttemptsExpireAndKeysAreIndependent(): void
    {
        $limiter = new PairingRateLimiter($this->directory);
        self::assertTrue($limiter->allow('a', 1, 10, 100));
        self::assertFalse($limiter->allow('a', 1, 10, 105));
        self::assertTrue($limiter->allow('b', 1, 10, 105));
        self::assertTrue($limiter->allow('a', 1, 10, 111));
    }
}
