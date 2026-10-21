<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\PasswordResetMail;

final class PasswordResetMailTest extends TestCase
{
    public function testRendersTheGermanMailWithTheLink(): void
    {
        $mail = PasswordResetMail::fromPayload([
            'email' => 'user@example.com',
            'name' => 'Erika',
            'link' => 'https://auth.tracht-digital.de/passwort-vergessen?token=abc',
            'validMinutes' => 60,
        ]);

        self::assertNotNull($mail);
        self::assertSame('Passwort zurücksetzen', $mail->subject);
        self::assertStringContainsString('href="https://auth.tracht-digital.de/passwort-vergessen?token=abc"', $mail->htmlBody);
        self::assertStringContainsString('60 Minuten', (string) $mail->textBody);
        self::assertStringContainsString('Hallo Erika,', $mail->htmlBody);
    }

    public function testRendersEnglishOnRequest(): void
    {
        $mail = PasswordResetMail::fromPayload([
            'email' => 'user@example.com',
            'link' => 'https://auth.tracht-digital.de/passwort-vergessen?token=abc&lang=en',
            'lang' => 'en',
        ]);

        self::assertNotNull($mail);
        self::assertSame('Reset your password', $mail->subject);
        self::assertStringContainsString('&amp;lang=en', $mail->htmlBody);
    }

    public function testEscapesTheName(): void
    {
        $mail = PasswordResetMail::fromPayload([
            'email' => 'user@example.com',
            'name' => '<script>x</script>',
            'link' => 'https://auth.example/r?token=1',
        ]);

        self::assertNotNull($mail);
        self::assertStringNotContainsString('<script>', $mail->htmlBody);
    }

    public function testRejectsAPlainHttpLinkOffLocalhostAndABadAddress(): void
    {
        self::assertNull(PasswordResetMail::fromPayload(['email' => 'user@example.com', 'link' => 'http://evil.example/x']));
        self::assertNull(PasswordResetMail::fromPayload(['email' => 'user@example.com', 'link' => 'javascript:alert(1)']));
        self::assertNull(PasswordResetMail::fromPayload(['email' => 'nope', 'link' => 'https://auth.example/r']));
        self::assertNotNull(PasswordResetMail::fromPayload(['email' => 'user@example.com', 'link' => 'http://localhost:4321/r']));
    }

    public function testBearerCheck(): void
    {
        self::assertTrue(PasswordResetMail::authorized('Bearer s3cret', 's3cret'));
        self::assertFalse(PasswordResetMail::authorized('Bearer wrong', 's3cret'));
        self::assertFalse(PasswordResetMail::authorized('s3cret', 's3cret'));
        // An unconfigured token must never match an empty bearer.
        self::assertFalse(PasswordResetMail::authorized('Bearer ', ''));
    }
}
