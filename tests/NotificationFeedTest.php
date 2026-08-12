<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Service\NotificationFeed;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\CoreFrontendApi\Support\NotificationCursor;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\NotificationSource;
use Tds\Frontend\Contract\UserContext;

/** A source with a scripted answer, so the merge rules can be driven directly. */
final class FakeSource extends AbstractModule implements NotificationSource
{
    /** @var list<?string> every cursor it was handed, in order */
    public array $seen = [];

    /** @param list<array<string,mixed>> $items */
    public function __construct(
        private readonly string $id,
        private readonly string $cursor = '1',
        private readonly array $items = [],
        private readonly bool $explode = false,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function register(App $app): void
    {
    }

    /** @return array{cursor: string, items: list<array<string,mixed>>} */
    public function notifications(UserContext $user, ?string $cursor): array
    {
        $this->seen[] = $cursor;
        if ($this->explode) {
            throw new \RuntimeException('boom');
        }
        return ['cursor' => $this->cursor, 'items' => $this->items];
    }
}

/**
 * The live notification feed — the one endpoint the panel shell polls on every
 * page.
 *
 * The assertions that matter are the two that keep a poller from becoming a
 * nuisance: a FIRST call must not replay the backlog (or every newly opened tab
 * fires a burst of toasts about things that happened last week), and a source
 * that blows up must lose only its own round (or one broken module stops the
 * shell polling anywhere).
 */
final class NotificationFeedTest extends TestCase
{
    private static function item(string $id, string $at): array
    {
        return ['id' => $id, 'module' => 'x', 'kind' => 'x.new', 'message' => $id, 'created_at' => $at];
    }

    private static function user(): UserContext
    {
        // The feed itself never inspects the principal — every source does its
        // own RBAC. Anonymous is fine here; the ROUTE is what rejects it.
        return new AnonymousUserContext();
    }

    public function testFirstCallReturnsACursorButNoItems(): void
    {
        $source = new FakeSource('a', '9', [self::item('a:1', '2026-08-01T10:00:00+00:00')]);
        $result = (new NotificationFeed([$source]))->collect(self::user(), null);

        self::assertSame([], $result['items']);
        self::assertSame(['a' => '9'], NotificationCursor::decode($result['cursor']));
        self::assertSame([null], $source->seen);
    }

    public function testASecondCallWithThatCursorDeliversTheItems(): void
    {
        $source = new FakeSource('a', '9', [self::item('a:1', '2026-08-01T10:00:00+00:00')]);
        $first = (new NotificationFeed([$source]))->collect(self::user(), null);
        $second = (new NotificationFeed([$source]))->collect(self::user(), $first['cursor']);

        self::assertCount(1, $second['items']);
        self::assertSame('a:1', $second['items'][0]['id']);
        self::assertSame([null, '9'], $source->seen);
    }

    public function testASOURCEAddedLATERStillGetsItsOwnFirstCall(): void
    {
        // The cursor is a per-module map, so enabling a module tomorrow must
        // not hand it the cursor of a module that already existed — nor replay
        // its history into a client that has been polling for weeks.
        $old = new FakeSource('old', '5', [self::item('old:1', '2026-08-01T10:00:00+00:00')]);
        $first = (new NotificationFeed([$old]))->collect(self::user(), null);

        $fresh = new FakeSource('fresh', '3', [self::item('fresh:1', '2026-08-01T11:00:00+00:00')]);
        $second = (new NotificationFeed([$old, $fresh]))->collect(self::user(), $first['cursor']);

        self::assertSame(['old:1'], array_column($second['items'], 'id'));
        self::assertSame(['old' => '5', 'fresh' => '3'], NotificationCursor::decode($second['cursor']));
    }

    public function testAThrowingSourceLosesOnlyItsOwnRound(): void
    {
        $broken = new FakeSource('broken', '1', [], true);
        $fine = new FakeSource('fine', '2', [self::item('fine:1', '2026-08-01T10:00:00+00:00')]);

        $cursor = NotificationCursor::encode(['broken' => '0', 'fine' => '1']);
        $result = (new NotificationFeed([$broken, $fine]))->collect(self::user(), $cursor);

        self::assertSame(['fine:1'], array_column($result['items'], 'id'));
        // No cursor recorded for the broken one, so its next poll is a first
        // call — it cannot suddenly replay a backlog once it recovers either.
        self::assertSame(['fine' => '2'], NotificationCursor::decode($result['cursor']));
    }

    public function testMergesSourcesOldestFirst(): void
    {
        // A burst should be announced in the order it happened, not grouped by
        // whichever module the registry happened to order first.
        $a = new FakeSource('a', '1', [self::item('a:1', '2026-08-01T12:00:00+00:00')]);
        $b = new FakeSource('b', '1', [self::item('b:1', '2026-08-01T09:00:00+00:00')]);
        $cursor = NotificationCursor::encode(['a' => '0', 'b' => '0']);

        $result = (new NotificationFeed([$a, $b]))->collect(self::user(), $cursor);

        self::assertSame(['b:1', 'a:1'], array_column($result['items'], 'id'));
    }

    public function testKeepsTheNEWESTOnOverflow(): void
    {
        $items = [];
        for ($i = 1; $i <= NotificationFeed::MAX_ITEMS + 5; $i++) {
            $items[] = self::item("a:{$i}", sprintf('2026-08-01T%02d:00:00+00:00', $i));
        }
        $source = new FakeSource('a', '1', $items);
        $cursor = NotificationCursor::encode(['a' => '0']);

        $result = (new NotificationFeed([$source]))->collect(self::user(), $cursor);

        self::assertCount(NotificationFeed::MAX_ITEMS, $result['items']);
        // Newest survive: a backlog of twenty is past what a reader can act on.
        self::assertSame('a:' . (NotificationFeed::MAX_ITEMS + 5), end($result['items'])['id']);
        self::assertSame('a:6', $result['items'][0]['id']);
    }

    public function testItemsAreAListAfterFiltering(): void
    {
        // json_encode turns a gappy array into an OBJECT — the client expects
        // an array and would iterate nothing.
        $source = new FakeSource('a', '1', [self::item('a:1', '2026-08-01T10:00:00+00:00'), 'not-an-array']);
        $cursor = NotificationCursor::encode(['a' => '0']);

        $result = (new NotificationFeed([$source]))->collect(self::user(), $cursor);

        self::assertSame('[', substr(json_encode($result['items'], JSON_THROW_ON_ERROR), 0, 1));
    }

    public function testNoSourcesIsAValidFeed(): void
    {
        // The base must work with an empty module list — extensions are additive.
        $result = (new NotificationFeed([]))->collect(self::user(), null);
        self::assertSame([], $result['items']);
        self::assertSame([], NotificationCursor::decode($result['cursor']));
    }
}
