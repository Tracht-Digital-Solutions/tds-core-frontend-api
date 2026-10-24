<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\AccountConfirmMail;

final class AccountConfirmMailTest extends TestCase
{
    public function testRendersTheGermanMailWithTheLink(): void
    {
        $mail = AccountConfirmMail::fromPayload([
            'email' => 'user@example.com',
            'name' => 'Erika',
            'link' => 'https://auth.tracht-digital.de/registrieren/bestaetigen?token=abc',
            'validHours' => 24,
        ]);

        self::assertNotNull($mail);
        self::assertSame('Konto bestätigen', $mail->subject);
        self::assertStringContainsString('href="https://auth.tracht-digital.de/registrieren/bestaetigen?token=abc"', $mail->htmlBody);
        self::assertStringContainsString('24 Stunden', (string) $mail->textBody);
        self::assertStringContainsString('Hallo Erika,', $mail->htmlBody);
    }

    public function testGermanCopyAddressesNoOne(): void
    {
        $mail = AccountConfirmMail::fromPayload(['email' => 'user@example.com', 'link' => 'https://auth.example/c?token=1']);

        self::assertNotNull($mail);
        self::assertDoesNotMatchRegularExpression('/\b(Sie|Ihr|Ihre|du|dein|deine)\b/u', (string) $mail->textBody);
    }

    public function testRendersEnglishOnRequest(): void
    {
        $mail = AccountConfirmMail::fromPayload([
            'email' => 'user@example.com',
            'link' => 'https://auth.example/c?token=abc&lang=en',
            'lang' => 'en',
        ]);

        self::assertNotNull($mail);
        self::assertSame('Confirm your account', $mail->subject);
        self::assertStringContainsString('&amp;lang=en', $mail->htmlBody);
    }

    public function testEscapesTheNameAndRejectsUnsafeLinks(): void
    {
        $mail = AccountConfirmMail::fromPayload([
            'email' => 'user@example.com',
            'name' => '<script>x</script>',
            'link' => 'https://auth.example/c?token=1',
        ]);
        self::assertNotNull($mail);
        self::assertStringNotContainsString('<script>', $mail->htmlBody);

        self::assertNull(AccountConfirmMail::fromPayload(['email' => 'user@example.com', 'link' => 'http://evil.example/x']));
        self::assertNull(AccountConfirmMail::fromPayload(['email' => 'user@example.com', 'link' => 'javascript:alert(1)']));
        self::assertNull(AccountConfirmMail::fromPayload(['email' => 'nope', 'link' => 'https://auth.example/c']));
    }
}
