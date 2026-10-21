<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\Email;

/**
 * The forgotten-password mail, sent on behalf of tds-auth-api
 * (`POST /internal/mail/password-reset`). auth-api issues the token and builds
 * the link; this service owns SMTP, so it renders and sends.
 *
 * The caller holds the service token, but the route is still reachable from
 * the internet, so the payload is checked as if it were not trusted: a valid
 * recipient, and a link that is https (http only for localhost) — a leaked
 * token must not turn the mailer into a relay for arbitrary links.
 */
final class PasswordResetMail
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
        $minutes = max(1, (int) ($body['validMinutes'] ?? 60));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || !self::acceptableLink($link)) {
            return null;
        }

        $safeLink = htmlspecialchars($link, ENT_QUOTES);
        $safeName = htmlspecialchars($name, ENT_QUOTES);

        if ($lang === 'en') {
            $greeting = $name !== '' ? "Hello {$safeName}," : 'Hello,';
            $html = "<p>{$greeting}</p>"
                . '<p>a new password was requested for your Tracht Digital Solutions account.</p>'
                . "<p><a href=\"{$safeLink}\">Set a new password</a></p>"
                . "<p>The link works once and for {$minutes} minutes. If you did not request it, ignore this mail — your password stays as it is.</p>";
            $text = ($name !== '' ? "Hello {$name}," : 'Hello,') . "\n\n"
                . "a new password was requested for your Tracht Digital Solutions account.\n\n"
                . "Set a new password: {$link}\n\n"
                . "The link works once and for {$minutes} minutes. If you did not request it, ignore this mail — your password stays as it is.\n";
            return new Email($to, $name, 'Reset your password', $html, $text);
        }

        $greeting = $name !== '' ? "Hallo {$safeName}," : 'Hallo,';
        $html = "<p>{$greeting}</p>"
            . '<p>für das Konto bei Tracht Digital Solutions wurde ein neues Passwort angefordert.</p>'
            . "<p><a href=\"{$safeLink}\">Neues Passwort festlegen</a></p>"
            . "<p>Der Link gilt einmal und {$minutes} Minuten lang. Wer die Anfrage nicht gestellt hat, kann diese Mail ignorieren — das Passwort bleibt unverändert.</p>";
        $text = ($name !== '' ? "Hallo {$name}," : 'Hallo,') . "\n\n"
            . "für das Konto bei Tracht Digital Solutions wurde ein neues Passwort angefordert.\n\n"
            . "Neues Passwort festlegen: {$link}\n\n"
            . "Der Link gilt einmal und {$minutes} Minuten lang. Wer die Anfrage nicht gestellt hat, kann diese Mail ignorieren — das Passwort bleibt unverändert.\n";
        return new Email($to, $name, 'Passwort zurücksetzen', $html, $text);
    }

    private static function acceptableLink(string $link): bool
    {
        $parts = parse_url($link);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        return $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true);
    }

    /** Constant-time bearer check; an empty configured token accepts nothing. */
    public static function authorized(string $authorizationHeader, string $serviceToken): bool
    {
        if ($serviceToken === '' || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return false;
        }
        return hash_equals($serviceToken, substr($authorizationHeader, 7));
    }
}
