<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Support;

use Tds\Frontend\Contract\MultiCompanyContext;
use Tds\Frontend\Contract\UserContext;

/**
 * A {@see UserContext} built from verified JWT claims + the `X-Act-As-Customer`
 * header. Consolidates the auth mapping the four services duplicated:
 *
 * - `admin` claim → {@see isAdmin()} (bypasses permission checks).
 * - `uid`/`sub` → the app_user id.
 * - Multi-company: the `companies` claim (`[{id, permissions}]`) + the header
 *   pick the active company and its permission set; falls back to the flat
 *   `customer_id`/`permissions` claims for pre-multi-company tokens. An admin
 *   may act as any customer via the header (the Admin-Ansicht).
 *
 * Also implements the contract's optional {@see MultiCompanyContext}, which
 * exposes the FULL membership list rather than just the active company — the
 * profile menu needs it to name the user's company, and the company switcher
 * will need it to offer the alternatives.
 */
final class JwtUserContext implements UserContext, MultiCompanyContext
{
    private readonly bool $admin;
    private readonly ?int $userId;
    private readonly ?string $email;
    private readonly ?int $activeCompanyId;
    /** @var string[] */
    private readonly array $permissionList;
    /** @var list<int> */
    private readonly array $companyIdList;

    /** @param array<string, mixed> $claims */
    public function __construct(array $claims, string $actAsHeader)
    {
        $this->admin = (bool) ($claims['admin'] ?? false);

        $uid = $claims['uid'] ?? $claims['sub'] ?? null;
        $this->userId = is_numeric($uid) ? (int) $uid : null;

        $mail = $claims['email'] ?? null;
        $this->email = is_string($mail) && $mail !== '' ? $mail : null;

        $companies = self::companies($claims);
        if ($this->admin) {
            // Admin: acts as whatever company the header names (or none).
            $this->activeCompanyId = self::headerId($actAsHeader);
            $this->permissionList = [];
            // Deliberately empty, not "all companies": an admin's reach is
            // "any company", which is not the same as belonging to one. The
            // token carries no memberships for an admin either.
            $this->companyIdList = [];
        } else {
            $this->activeCompanyId = self::resolveCompany($claims, $companies, $actAsHeader);
            $this->permissionList = self::permissionsFor($claims, $companies, $this->activeCompanyId);
            $this->companyIdList = array_values(array_map(
                static fn (array $c): int => (int) $c['id'],
                $companies,
            ));
        }
    }

    /**
     * {@inheritDoc}
     *
     * In token order, so the first entry is the primary/default company —
     * which is what `app_user.customer_id` denormalises on the auth side.
     */
    public function companyIds(): array
    {
        return $this->companyIdList;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->permissionList;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->permissionList, true);
    }

    public function activeCompanyId(): ?int
    {
        return $this->activeCompanyId;
    }

    // --- claim helpers (ported from customer-api's ActiveCompany) -------------

    /**
     * @param array<string, mixed> $claims
     * @return list<array{id: int, permissions: string[]}>
     */
    private static function companies(array $claims): array
    {
        $raw = $claims['companies'] ?? null;
        if ($raw === null) {
            return [];
        }
        // JWT decode yields stdClass for nested objects — normalise to arrays.
        $norm = json_decode(json_encode($raw), true);
        if (!is_array($norm)) {
            return [];
        }
        $out = [];
        foreach ($norm as $c) {
            if (is_array($c) && isset($c['id'])) {
                $out[] = [
                    'id' => (int) $c['id'],
                    'permissions' => array_values(array_map('strval', (array) ($c['permissions'] ?? []))),
                ];
            }
        }
        return $out;
    }

    private static function headerId(string $header): ?int
    {
        $header = trim($header);
        return ($header !== '' && ctype_digit($header)) ? (int) $header : null;
    }

    /**
     * @param array<string, mixed> $claims
     * @param list<array{id: int, permissions: string[]}> $companies
     */
    private static function resolveCompany(array $claims, array $companies, string $header): ?int
    {
        $allowed = array_map(static fn (array $c): int => $c['id'], $companies);
        $cid = $claims['customer_id'] ?? null;
        if ($allowed === [] && is_int($cid) && $cid > 0) {
            $allowed[] = $cid;
        }

        $requested = self::headerId($header);
        if ($requested !== null && in_array($requested, $allowed, true)) {
            return $requested;
        }
        if (is_int($cid) && $cid > 0) {
            return $cid;
        }
        return $allowed[0] ?? null;
    }

    /**
     * @param array<string, mixed> $claims
     * @param list<array{id: int, permissions: string[]}> $companies
     * @return string[]
     */
    private static function permissionsFor(array $claims, array $companies, ?int $companyId): array
    {
        if ($companyId !== null) {
            foreach ($companies as $c) {
                if ($c['id'] === $companyId) {
                    return $c['permissions'];
                }
            }
        }
        // Only fall back to the flat claim for pre-multi-company tokens (no
        // companies claim at all); a present-but-non-matching claim = no perms.
        if ($companies === []) {
            $flat = $claims['permissions'] ?? [];
            return is_array($flat) ? array_values(array_map('strval', $flat)) : [];
        }
        return [];
    }
}
