<?php

declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\StripeConfig;
use Tds\Frontend\Contract\SettingsStore;

final class StripeConfigTest extends TestCase
{
    private function store(?string $secret): SettingsStore
    {
        $store = $this->createMock(SettingsStore::class);
        $store->method('getSecret')->willReturn($secret);
        return $store;
    }

    public function testStoredKeyWinsOverTheEnv(): void
    {
        $config = StripeConfig::resolve($this->store('sk_live_stored1234'), static fn () => 'sk_test_env');

        self::assertSame(
            ['configured' => true, 'source' => 'db', 'mode' => 'live', 'last4' => '1234'],
            $config->status(),
        );
    }

    public function testEnvIsTheFallback(): void
    {
        $config = StripeConfig::resolve($this->store(null), static fn (string $k, ?string $d) => $k === 'STRIPE_SECRET_KEY' ? 'sk_test_abcd' : (string) $d);

        self::assertSame('env', $config->source);
        self::assertSame('test', $config->mode());
    }

    public function testNothingConfigured(): void
    {
        $config = StripeConfig::resolve(null, static fn () => '');

        self::assertFalse($config->isConfigured());
        self::assertSame(['configured' => false, 'source' => 'none', 'mode' => null, 'last4' => null], $config->status());
    }

    public function testAdminRoutesRequireAdmin(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $factory = new ServerRequestFactory();
        self::assertSame(401, $app->handle($factory->createServerRequest('GET', '/admin/stripe'))->getStatusCode());
        self::assertSame(401, $app->handle($factory->createServerRequest('POST', '/admin/stripe/test'))->getStatusCode());
    }
}
