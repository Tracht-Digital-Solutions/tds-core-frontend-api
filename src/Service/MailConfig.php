<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * Resolved SMTP configuration for the base {@see \Tds\Frontend\Contract\Mailer}
 * — the one transport every composed module sends through (Ticket-Benachrichtigungen,
 * Kontakt-Antworten, Live-Chat-Mails …).
 *
 * Read **DB-first with an env fallback**, the platform's standard pattern for
 * third-party config ({@see SettingsStore} namespace `mail`, editable under
 * Einstellungen → *E-Mail (SMTP)*). Before this existed the mailer was
 * `MAIL_DSN`-only, i.e. configurable exclusively by editing a file on the
 * production host — so every notification toggle in the panel switched on a
 * mailer nobody could set up from the panel.
 *
 * Precedence, and the order matters:
 *   1. a stored raw `dsn` (the escape hatch for a transport the form cannot
 *      express, e.g. `sendmail://default`),
 *   2. the stored host/port/user/password fields,
 *   3. `MAIL_DSN` from the environment,
 *   4. nothing — the base then binds {@see NullMailer}, `isConfigured()` stays
 *      false, and a module skips or annotates its notification.
 *
 * The stored fields outrank `MAIL_DSN` on purpose: otherwise an `.env` written
 * once at install time would permanently shadow the form, which is exactly the
 * failure mode this class exists to remove.
 *
 * Never throws. Without a database — the state the frontend service is in until
 * `services/frontend/.env` + `tds_frontend` exist — it resolves to the env
 * values, so the settings page renders "nicht konfiguriert" instead of a 500.
 */
final class MailConfig
{
    public const NAMESPACE = 'mail';

    /** Transport security, in the values the settings form writes. */
    public const SECURITY_STARTTLS = 'tls';
    public const SECURITY_IMPLICIT = 'ssl';
    public const SECURITY_NONE = 'none';

    /**
     * Declared keys, in form order: [key, secret]. The form renders exactly
     * these, so a new setting is one edit here plus one field in
     * `MailSettings.tsx`.
     *
     * @var list<array{0:string,1:bool}>
     */
    public const KEYS = [
        ['host', false],
        ['port', false],
        ['security', false],
        ['user', false],
        ['password', true],
        ['from_email', false],
        ['from_name', false],
        ['dsn', true],
    ];

    private function __construct(
        public readonly string $dsn,
        public readonly string $fromEmail,
        public readonly string $fromName,
        /** `db` | `env` | `none` — which layer the transport came from. */
        public readonly string $source,
        public readonly string $host,
        public readonly int $port,
        public readonly string $security,
        public readonly string $user,
        public readonly bool $passwordConfigured,
    ) {
    }

    /** @param callable(string,?string):string $env The Bootstrap env reader. */
    public static function resolve(?SettingsStoreContract $store, callable $env): self
    {
        $read = static function (string $key, bool $secret) use ($store): string {
            try {
                $stored = $secret ? $store?->getSecret(self::NAMESPACE, $key) : $store?->get(self::NAMESPACE, $key);
            } catch (\Throwable) {
                // No DB yet (or an undecryptable value) — fall through to env.
                $stored = null;
            }
            return is_string($stored) ? trim($stored) : '';
        };

        $storedDsn = $read('dsn', true);
        $host = $read('host', false);
        $storedPort = $read('port', false);
        $port = $storedPort === '' ? 587 : (int) $storedPort;
        $security = self::normalizeSecurity($read('security', false));
        $user = $read('user', false);
        $password = $read('password', true);

        $envDsn = trim((string) $env('MAIL_DSN', ''));

        if ($storedDsn !== '') {
            $dsn = $storedDsn;
            $source = 'db';
        } elseif ($host !== '') {
            $dsn = self::buildDsn($host, $port, $security, $user, $password);
            $source = 'db';
        } elseif ($envDsn !== '') {
            $dsn = $envDsn;
            $source = 'env';
        } else {
            $dsn = '';
            $source = 'none';
        }

        $fromEmail = $read('from_email', false);
        if ($fromEmail === '') {
            $fromEmail = (string) $env('MAIL_FROM', 'no-reply@tracht-digital.de');
        }
        $fromName = $read('from_name', false);
        if ($fromName === '') {
            $fromName = (string) $env('MAIL_FROM_NAME', 'Tracht Digital Solutions');
        }

        return new self(
            dsn: $dsn,
            fromEmail: $fromEmail,
            fromName: $fromName,
            source: $source,
            host: $host,
            port: $port,
            security: $security,
            user: $user,
            passwordConfigured: $password !== '',
        );
    }

    public function isConfigured(): bool
    {
        return $this->dsn !== '';
    }

    /**
     * What the settings page shows. Deliberately carries no secret: the DSN can
     * embed the SMTP password, so it never leaves the server — only *whether*
     * one is stored.
     *
     * @return array{configured:bool,source:string,host:string,port:int,security:string,user:string,password_configured:bool,from_email:string,from_name:string}
     */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'source' => $this->source,
            'host' => $this->host,
            'port' => $this->port,
            'security' => $this->security,
            'user' => $this->user,
            'password_configured' => $this->passwordConfigured,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
        ];
    }

    /**
     * Strip credentials out of a transport error before it reaches the panel.
     * Symfony echoes the DSN in some failures and the DSN carries the SMTP
     * password — an admin-only route is still no reason to hand it back.
     */
    public static function redact(string $message): string
    {
        // `scheme://user:password@host` → `scheme://user:***@host`
        return (string) preg_replace('#(://[^:/@\s]+):[^@\s]+@#', '$1:***@', $message);
    }

    /**
     * Symfony's SMTP DSN. `smtps://` is implicit TLS (the 465 style);
     * `smtp://` negotiates STARTTLS when the server offers it — which is why
     * "keine Verschlüsselung" has to say so explicitly via `auto_tls=false`
     * instead of just picking the plain scheme.
     */
    private static function buildDsn(string $host, int $port, string $security, string $user, string $password): string
    {
        $scheme = $security === self::SECURITY_IMPLICIT ? 'smtps' : 'smtp';
        $credentials = '';
        if ($user !== '') {
            $credentials = rawurlencode($user) . ':' . rawurlencode($password) . '@';
        }
        $dsn = $scheme . '://' . $credentials . $host;
        if ($port > 0) {
            $dsn .= ':' . $port;
        }
        if ($security === self::SECURITY_NONE) {
            $dsn .= '?auto_tls=false';
        }
        return $dsn;
    }

    private static function normalizeSecurity(string $value): string
    {
        return match (strtolower($value)) {
            self::SECURITY_IMPLICIT, 'smtps', 'implicit' => self::SECURITY_IMPLICIT,
            self::SECURITY_NONE, 'off', '0' => self::SECURITY_NONE,
            default => self::SECURITY_STARTTLS,
        };
    }
}
