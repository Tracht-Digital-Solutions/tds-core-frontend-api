<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\MailConfig;
use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The base's SMTP configuration: what actually sends, and what the settings page
 * is allowed to see.
 *
 * The rules worth pinning are the ones a reader would get wrong: the stored
 * configuration must beat `MAIL_DSN` (or an `.env` written once at install time
 * shadows the form forever), a host without a database must resolve rather than
 * throw (that is the frontend service's state until `services/frontend/.env`
 * exists), and neither the status payload nor an SMTP error may carry the
 * password.
 */
final class MailConfigTest extends TestCase
{
    /** @param array<string,string> $values */
    private function store(array $values): SettingsStoreContract
    {
        return new class ($values) implements SettingsStoreContract {
            /** @param array<string,string> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                return $this->values[$key] ?? $default;
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
            }

            public function delete(string $namespace, string $key): void
            {
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };
    }

    /** @param array<string,string> $env */
    private function env(array $env): callable
    {
        return static fn (string $key, ?string $default = null): string => $env[$key] ?? (string) $default;
    }

    // --- Resolution ---------------------------------------------------------

    public function testUnconfiguredResolvesToNothingRatherThanThrowing(): void
    {
        $config = MailConfig::resolve(null, $this->env([]));

        self::assertFalse($config->isConfigured());
        self::assertSame('none', $config->source);
        // The From identity still resolves — the settings page shows it as the
        // default rather than an empty field.
        self::assertSame('no-reply@tracht-digital.de', $config->fromEmail);
    }

    public function testEnvDsnIsTheFallback(): void
    {
        $config = MailConfig::resolve(null, $this->env([
            'MAIL_DSN' => 'smtp://user:pass@mail.example.net:587',
            'MAIL_FROM' => 'hallo@example.net',
        ]));

        self::assertTrue($config->isConfigured());
        self::assertSame('env', $config->source);
        self::assertSame('hallo@example.net', $config->fromEmail);
    }

    public function testStoredHostBuildsTheDsnAndBeatsTheEnvFallback(): void
    {
        // The whole point of the feature: a panel-configured transport must win,
        // or the form is decorative on any host that has a MAIL_DSN.
        $config = MailConfig::resolve($this->store([
            'host' => 'smtp.example.net',
            'port' => '587',
            'user' => 'noreply@example.net',
            'password' => 'p@ss word',
        ]), $this->env(['MAIL_DSN' => 'smtp://old:old@legacy.example.net:25']));

        self::assertSame('db', $config->source);
        self::assertSame('smtp://noreply%40example.net:p%40ss%20word@smtp.example.net:587', $config->dsn);
        self::assertTrue($config->passwordConfigured);
    }

    public function testImplicitTlsUsesTheSmtpsScheme(): void
    {
        $config = MailConfig::resolve($this->store([
            'host' => 'smtp.example.net',
            'port' => '465',
            'security' => 'ssl',
        ]), $this->env([]));

        self::assertSame('smtps://smtp.example.net:465', $config->dsn);
    }

    public function testNoEncryptionMustSaySoExplicitly(): void
    {
        // `smtp://` alone still negotiates STARTTLS when the server offers it,
        // so "keine Verschlüsselung" needs the flag, not just the plain scheme.
        $config = MailConfig::resolve($this->store([
            'host' => 'localhost',
            'port' => '25',
            'security' => 'none',
        ]), $this->env([]));

        self::assertSame('smtp://localhost:25?auto_tls=false', $config->dsn);
    }

    public function testRawDsnOverridesTheStructuredFields(): void
    {
        $config = MailConfig::resolve($this->store([
            'dsn' => 'sendmail://default',
            'host' => 'smtp.example.net',
        ]), $this->env([]));

        self::assertSame('sendmail://default', $config->dsn);
        self::assertSame('db', $config->source);
    }

    public function testAStoreWithoutADatabaseFallsBackInsteadOfThrowing(): void
    {
        // The frontend service boots with no DB until services/frontend/.env
        // exists; the settings page must render there, not 500.
        $store = new class implements SettingsStoreContract {
            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                throw new \RuntimeException('no database');
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                throw new \RuntimeException('no database');
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
            }

            public function delete(string $namespace, string $key): void
            {
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };

        $config = MailConfig::resolve($store, $this->env(['MAIL_DSN' => 'smtp://mail.example.net']));

        self::assertSame('env', $config->source);
        self::assertTrue($config->isConfigured());
    }

    // --- Secret handling ----------------------------------------------------

    public function testStatusNeverCarriesTheSecret(): void
    {
        $status = MailConfig::resolve($this->store([
            'host' => 'smtp.example.net',
            'user' => 'noreply@example.net',
            'password' => 'hunter2',
        ]), $this->env([]))->status();

        self::assertTrue($status['password_configured']);
        self::assertStringNotContainsString('hunter2', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testTransportErrorsAreRedacted(): void
    {
        // Symfony echoes the DSN in some failures, and the DSN embeds the SMTP
        // password. Admin-only is not a reason to hand it back.
        self::assertSame(
            'Could not connect: smtp://user:***@mail.example.net:587',
            MailConfig::redact('Could not connect: smtp://user:hunter2@mail.example.net:587'),
        );
    }

    // --- Route gating -------------------------------------------------------

    public function testStatusRouteRequiresAdmin(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/mail');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    public function testTestMailRouteRequiresAdmin(): void
    {
        // A 404 would mean the route was never mounted; a 200 would make the
        // API a mail relay for anyone who can reach it.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/mail/test');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }
}
