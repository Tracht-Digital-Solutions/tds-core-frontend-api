<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Service\SetupStatus;
use Tds\CoreFrontendApi\Support\JwtUserContext;
use Tds\Frontend\Contract\SetupStatusSource;
use Tds\Frontend\Contract\UserContext;

final class SetupStatusTest extends TestCase
{
    private const SESSION = 1_791_600_000;

    private static function item(string $id, string $state = 'missing', string $level = 'recommended'): array
    {
        return ['id' => $id, 'module' => strstr($id, ':', true), 'title' => $id, 'description' => '', 'state' => $state, 'level' => $level, 'href' => '/einstellungen#settings-x'];
    }

    private static function source(array $items, bool $throws = false): SetupStatusSource
    {
        return new class ($items, $throws) implements SetupStatusSource {
            public function __construct(private array $items, private bool $throws)
            {
            }

            public function setupItems(UserContext $user): array
            {
                if ($this->throws) {
                    throw new \RuntimeException('broken source');
                }
                return $this->items;
            }
        };
    }

    private static function admin(): UserContext
    {
        return new JwtUserContext(['admin' => true, 'uid' => 1, 'auth_time' => self::SESSION], '');
    }

    public function test_a_snooze_holds_for_this_session_and_ends_with_the_next_sign_in(): void
    {
        $wizard = new SetupStatus([self::source([self::item('shop:amazon')])]);
        $prefs = SetupStatus::choice('shop:amazon', 'snooze', self::SESSION);

        $now = $wizard->collect(self::admin(), $prefs, self::SESSION);
        self::assertTrue($now['items'][0]['snoozed']);
        self::assertSame(0, $now['open']);

        $nextLogin = $wizard->collect(self::admin(), $prefs, self::SESSION + 86_400);
        self::assertFalse($nextLogin['items'][0]['snoozed']);
        self::assertSame(1, $nextLogin['open']);
    }

    public function test_ignore_is_permanent_and_restore_undoes_it(): void
    {
        $wizard = new SetupStatus([self::source([self::item('shop:amazon')])]);
        $ignored = SetupStatus::choice('shop:amazon', 'ignore', self::SESSION);
        $later = $wizard->collect(self::admin(), $ignored, self::SESSION + 999_999);
        self::assertTrue($later['items'][0]['ignored']);
        self::assertSame(0, $later['open']);

        $restored = array_merge($ignored, SetupStatus::choice('shop:amazon', 'restore', self::SESSION));
        self::assertSame(1, $wizard->collect(self::admin(), $restored, self::SESSION)['open']);
    }

    public function test_a_broken_source_costs_only_its_own_items(): void
    {
        $wizard = new SetupStatus([self::source([], true), self::source([self::item('lexware:api')])], [self::item('core:mail')]);
        $ids = array_column($wizard->collect(self::admin(), [], self::SESSION)['items'], 'id');
        self::assertEqualsCanonicalizing(['core:mail', 'lexware:api'], $ids);
    }

    public function test_open_and_required_items_come_first_and_done_items_do_not_count(): void
    {
        $wizard = new SetupStatus([self::source([
            self::item('a:done', 'ok', 'required'),
            self::item('b:optional', 'missing', 'optional'),
            self::item('c:required', 'partial', 'required'),
        ])]);
        $out = $wizard->collect(self::admin(), [], self::SESSION);
        self::assertSame(['c:required', 'b:optional', 'a:done'], array_column($out['items'], 'id'));
        self::assertSame(2, $out['open']);
    }

    public function test_malformed_items_and_foreign_links_are_cleaned(): void
    {
        $bad = self::item('no-colon');
        $evil = ['id' => 'x:y', 'title' => 'T', 'state' => 'weird', 'level' => 'odd', 'href' => 'https://evil.example/'];
        $wizard = new SetupStatus([self::source([$bad, $evil, $evil])]);
        $items = $wizard->collect(self::admin(), [], self::SESSION)['items'];
        self::assertCount(1, $items, 'malformed id dropped, duplicate id dropped');
        self::assertSame('/einstellungen', $items[0]['href']);
        self::assertSame('missing', $items[0]['state']);
        self::assertSame('recommended', $items[0]['level']);
    }

    public function test_the_session_falls_back_to_iat_for_an_older_token(): void
    {
        self::assertSame(123, (new JwtUserContext(['uid' => 1, 'iat' => 123], ''))->sessionStartedAt());
        self::assertSame(self::SESSION, self::admin()->sessionStartedAt());
        self::assertSame([], SetupStatus::choice('a:b', 'explode', self::SESSION));
    }
}
