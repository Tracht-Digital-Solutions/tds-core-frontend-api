<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\CoreFrontendApi\Support\NotificationCursor;
use Tds\Frontend\Contract\NotificationSource;
use Tds\Frontend\Contract\UserContext;

/**
 * Merges every composed {@see NotificationSource} into the single feed the
 * panel shell polls (`GET /me/notifications`).
 *
 * Extracted from the route so the four rules below can be tested without an
 * authenticated request — each of them is the kind of thing that is invisible
 * until it is annoying in production.
 */
final class NotificationFeed
{
    /** Most events one poll may return. */
    public const MAX_ITEMS = 20;

    /** @param NotificationSource[] $sources */
    public function __construct(private readonly array $sources)
    {
    }

    /**
     * @return array{cursor: string, items: list<array<string,mixed>>}
     */
    public function collect(UserContext $user, ?string $since): array
    {
        $incoming = NotificationCursor::decode($since);

        $items = [];
        $cursors = [];
        foreach ($this->sources as $source) {
            $id = $source instanceof \Tds\Frontend\Contract\Module ? $source->id() : '';
            if ($id === '') {
                continue;
            }
            $known = $incoming[$id] ?? null;
            try {
                $result = $source->notifications($user, $known);
            } catch (\Throwable) {
                // The contract says a source must not throw. If one does anyway
                // it loses this round — it must not take the feed, and with it
                // the shell's polling on every page, down with it. No cursor is
                // recorded either, so the next poll is a first call for it and
                // it still cannot replay a backlog.
                continue;
            }

            $cursors[$id] = (string) ($result['cursor'] ?? '');

            // A source whose cursor did not arrive is seeing this client for
            // the first time: hand back the cursor, drop the items. Otherwise
            // every freshly opened tab toasts everything that ever happened.
            // Enforced HERE rather than trusting each source, because the base
            // is the only place that can see whether a cursor came in.
            if ($known === null) {
                continue;
            }
            foreach ($result['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        // Oldest first, so a burst is announced in the order it happened.
        usort($items, static fn (array $a, array $b): int =>
            (string) ($a['created_at'] ?? '') <=> (string) ($b['created_at'] ?? ''));

        // On overflow keep the NEWEST: twenty at once is past what a reader can
        // act on, and the recent ones are the ones that still matter.
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, -self::MAX_ITEMS);
        }

        return ['cursor' => NotificationCursor::encode($cursors), 'items' => array_values($items)];
    }
}
