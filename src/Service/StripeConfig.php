<?php

declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SettingsStore as SettingsStoreContract;

/**
 * The platform's Stripe account — Einstellungen → Zahlungen (Stripe).
 *
 * One secret key for every module that charges (Rechnungen, Premium-Tools,
 * Shop). It is read DB-first from the `stripe` settings namespace, with
 * `STRIPE_SECRET_KEY` from the host's `.env` as the fallback, exactly like the
 * mail transport. A module may still keep its own key; it then overrides this
 * one for that module only.
 *
 * Never throws: without a database it resolves to the env value, so the
 * settings page renders a state instead of a 500.
 */
final class StripeConfig
{
    public const NAMESPACE = 'stripe';

    private function __construct(
        public readonly string $secretKey,
        /** `db`, `env` or `none` — where the active key comes from. */
        public readonly string $source,
    ) {
    }

    /** @param callable(string, ?string): string $env */
    public static function resolve(?SettingsStoreContract $store, callable $env): self
    {
        $stored = null;
        if ($store !== null) {
            try {
                $stored = $store->getSecret(self::NAMESPACE, 'secret_key');
            } catch (\Throwable) {
                $stored = null; // no DB yet — fall through to the env
            }
        }
        if (is_string($stored) && trim($stored) !== '') {
            return new self(trim($stored), 'db');
        }
        $fromEnv = trim((string) $env('STRIPE_SECRET_KEY', ''));
        return $fromEnv !== '' ? new self($fromEnv, 'env') : new self('', 'none');
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /** `test` / `live` from the key prefix; a restricted key (rk_) counts too. */
    public function mode(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        return str_contains(substr($this->secretKey, 0, 8), '_test') ? 'test' : 'live';
    }

    /** @return array{configured:bool,source:string,mode:?string,last4:?string} */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'source' => $this->source,
            'mode' => $this->mode(),
            'last4' => $this->isConfigured() ? substr($this->secretKey, -4) : null,
        ];
    }
}
