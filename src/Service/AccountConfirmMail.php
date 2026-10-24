<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\Email;

/**
 * The "confirm your address" mail for a self-registered account, sent on
 * behalf of tds-auth-api (`POST /internal/mail/account-confirm`). auth-api
 * creates the pending account, issues the token and builds the link; this
 * service owns SMTP, so it renders and sends.
 *
 * Same trust rules as PasswordResetMail: the payload is checked as if the
 * service token had leaked — a valid recipient and an https link only.
 */
final class AccountConfirmMail
{
    /**
     * @param array<string,mixed> $body
     * @return Email|null null when the payload is not acceptable
     */
    public static function fromPayload(array $body): ?Email
    {
        $to = trim((string) ($body['email'] ?? ''));
        $link = trim((string) ($body['link'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $lang = ($body['lang'] ?? '') === 'en' ? 'en' : 'de';
        $hours = max(1, (int) ($body['validHours'] ?? 24));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || !PasswordResetMail::acceptableLink($link)) {
            return null;
        }

        $safeLink = htmlspecialchars($link, ENT_QUOTES);
        $safeName = htmlspecialchars($name, ENT_QUOTES);

        if ($lang === 'en') {
            $greeting = $name !== '' ? "Hello {$safeName}," : 'Hello,';
            $html = "<p>{$greeting}</p>"
                . '<p>an account at Tracht Digital Solutions was created with this address.</p>'
                . "<p><a href=\"{$safeLink}\">Confirm the address</a></p>"
                . "<p>The link works once and for {$hours} hours. If you did not sign up, ignore this mail — the account is never activated.</p>";
            $text = ($name !== '' ? "Hello {$name}," : 'Hello,') . "\n\n"
                . "an account at Tracht Digital Solutions was created with this address.\n\n"
                . "Confirm the address: {$link}\n\n"
                . "The link works once and for {$hours} hours. If you did not sign up, ignore this mail — the account is never activated.\n";
            return new Email($to, $name, 'Confirm your account', $html, $text);
        }

        $greeting = $name !== '' ? "Hallo {$safeName}," : 'Hallo,';
        $html = "<p>{$greeting}</p>"
            . '<p>mit dieser Adresse wurde ein Konto bei Tracht Digital Solutions angelegt.</p>'
            . "<p><a href=\"{$safeLink}\">Adresse bestätigen</a></p>"
            . "<p>Der Link gilt einmal und {$hours} Stunden lang. Wer sich nicht registriert hat, kann diese Mail ignorieren — das Konto wird dann nie aktiv.</p>";
        $text = ($name !== '' ? "Hallo {$name}," : 'Hallo,') . "\n\n"
            . "mit dieser Adresse wurde ein Konto bei Tracht Digital Solutions angelegt.\n\n"
            . "Adresse bestätigen: {$link}\n\n"
            . "Der Link gilt einmal und {$hours} Stunden lang. Wer sich nicht registriert hat, kann diese Mail ignorieren — das Konto wird dann nie aktiv.\n";
        return new Email($to, $name, 'Konto bestätigen', $html, $text);
    }
}
