<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Support\JwtUserContext;

/**
 * Claim → principal mapping (the logic the four services duplicated), without a
 * live JWKS: constructs the context directly from decoded claims.
 */
final class JwtUserContextTest extends TestCase
{
    public function testAdminBypassesPermissionsAndActsAsHeaderCompany(): void
    {
        $ctx = new JwtUserContext(['admin' => true, 'uid' => 7, 'email' => 'a@x.de'], '42');

        self::assertTrue($ctx->isAuthenticated());
        self::assertTrue($ctx->isAdmin());
        self::assertSame(7, $ctx->userId());
        self::assertSame('a@x.de', $ctx->email());
        self::assertTrue($ctx->has('anything:at-all')); // admin bypass
        self::assertSame(42, $ctx->activeCompanyId());  // acts as the header company
    }

    public function testMultiCompanyResolvesActiveCompanyAndItsPermissions(): void
    {
        $claims = [
            'admin' => false,
            'sub' => 5,
            'customer_id' => 10,
            'companies' => [
                ['id' => 10, 'permissions' => ['tickets:read']],
                ['id' => 20, 'permissions' => ['tickets:read', 'tickets:write']],
            ],
        ];

        // Requests company 20 (a membership) → that company's permissions.
        $ctx = new JwtUserContext($claims, '20');
        self::assertSame(20, $ctx->activeCompanyId());
        self::assertTrue($ctx->has('tickets:write'));

        // No header → primary company (customer_id 10), which lacks :write.
        $primary = new JwtUserContext($claims, '');
        self::assertSame(10, $primary->activeCompanyId());
        self::assertTrue($primary->has('tickets:read'));
        self::assertFalse($primary->has('tickets:write'));
    }

    public function testRejectsCompanyTheLoginDoesNotBelongTo(): void
    {
        $claims = [
            'admin' => false,
            'customer_id' => 10,
            'companies' => [['id' => 10, 'permissions' => ['tickets:read']]],
        ];
        // Header asks for 99 (not a membership) → falls back to primary 10, no 99 perms.
        $ctx = new JwtUserContext($claims, '99');
        self::assertSame(10, $ctx->activeCompanyId());
    }

    public function testFallsBackToFlatPermissionsForPreMultiCompanyToken(): void
    {
        $ctx = new JwtUserContext(
            ['admin' => false, 'customer_id' => 3, 'permissions' => ['documents:read']],
            '',
        );
        self::assertSame(3, $ctx->activeCompanyId());
        self::assertTrue($ctx->has('documents:read'));
    }

    // --- the customer → company rename, both spellings for one release ------

    public function testReadsTheNewCompanyIdClaim(): void
    {
        $ctx = new JwtUserContext(['admin' => false, 'company_id' => 3], '');

        self::assertSame(3, $ctx->activeCompanyId());
    }

    public function testStillReadsTheOldCustomerIdClaim(): void
    {
        // A token minted before the rename stays valid for up to an hour, and
        // this service does not deploy at the same instant as auth-api. Reading
        // only the new name would silently strip a portal user of their tenant
        // — every scoped list comes back empty with no error anywhere.
        $ctx = new JwtUserContext(['admin' => false, 'customer_id' => 3], '');

        self::assertSame(3, $ctx->activeCompanyId());
    }

    public function testPrefersTheNewClaimWhenBothArePresent(): void
    {
        // auth-api emits both during the transition; they agree, but the
        // current spelling is the one to trust.
        $ctx = new JwtUserContext(
            ['admin' => false, 'company_id' => 3, 'customer_id' => 9],
            '',
        );

        self::assertSame(3, $ctx->activeCompanyId());
    }

    public function testExposesTheFullMembershipListForTheSwitcher(): void
    {
        $ctx = new JwtUserContext([
            'admin' => false,
            'companies' => [
                ['id' => 3, 'permissions' => []],
                ['id' => 9, 'permissions' => []],
            ],
        ], '');

        self::assertSame([3, 9], $ctx->companyIds());
    }

    public function testAnAdminReportsNoMemberships(): void
    {
        // Their reach is "any company", which is not belonging to one —
        // returning every company here would turn a convenience accessor into
        // an unbounded directory read.
        $ctx = new JwtUserContext(['admin' => true, 'companies' => []], '7');

        self::assertSame([], $ctx->companyIds());
        self::assertSame(7, $ctx->activeCompanyId(), 'but may still act as one');
    }
}
