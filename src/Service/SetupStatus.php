<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

use Tds\Frontend\Contract\SetupStatusSource;
use Tds\Frontend\Contract\UserContext;

/**
 * The panel's setup wizard: merge every module's setup items with the base's
 * own and apply the user's choices.
 *
 * ### "Später" means "until the next sign-in"
 *
 * A snooze stores the session it was made in — the token's `auth_time`, which
 * the auth API carries unchanged through every hourly refresh. The item stays
 * hidden while that is still the session, and comes back on the next login.
 * `jti` or `iat` would bring it back every hour.
 *
 * ### Choices live in `user_preference`
 *
 * `setup:snooze:<item-id>` = the session's `auth_time`, `setup:ignore:<item-id>`
 * = `1`. The table is a key/value store precisely so new keys need no
 * migration; these keys are written only by the setup routes, never by
 * `PUT /me/preferences`, whose whitelist does not know them.
 */
final class SetupStatus
{
    public const SNOOZE = 'setup:snooze:';
    public const IGNORE = 'setup:ignore:';

    private const STATES = ['ok', 'missing', 'partial'];
    private const LEVELS = ['required', 'recommended', 'optional'];

    /**
     * @param SetupStatusSource[]             $sources
     * @param list<array<string,string>>      $builtin the base's own items
     */
    public function __construct(private readonly array $sources, private readonly array $builtin = [])
    {
    }

    /**
     * @param array<string,string> $prefs   the user's stored preferences
     * @param int|null             $session the current session's sign-in time
     * @return array{items: list<array<string,mixed>>, open: int}
     */
    public function collect(UserContext $user, array $prefs, ?int $session): array
    {
        $raw = $this->builtin;
        foreach ($this->sources as $source) {
            try {
                foreach ($source->setupItems($user) as $item) {
                    $raw[] = $item;
                }
            } catch (\Throwable) {
                // The contract says a source never throws. One that does costs
                // its own items, never the wizard.
            }
        }

        $items = [];
        $seen = [];
        $open = 0;
        foreach ($raw as $item) {
            $clean = self::clean($item);
            if ($clean === null || isset($seen[$clean['id']])) {
                continue;
            }
            $seen[$clean['id']] = true;

            $snoozedIn = $prefs[self::SNOOZE . $clean['id']] ?? '';
            $clean['snoozed'] = $session !== null && $snoozedIn !== '' && (int) $snoozedIn === $session;
            $clean['ignored'] = ($prefs[self::IGNORE . $clean['id']] ?? '') === '1';
            if ($clean['state'] !== 'ok' && !$clean['snoozed'] && !$clean['ignored']) {
                $open++;
            }
            $items[] = $clean;
        }

        // Most urgent first: open before done, required before optional.
        $rank = static fn (array $i): array => [
            $i['state'] === 'ok' ? 1 : 0,
            array_search($i['level'], self::LEVELS, true),
            $i['module'],
            $i['title'],
        ];
        usort($items, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return ['items' => $items, 'open' => $open];
    }

    /**
     * The preference writes for one choice.
     *
     * @return array<string,string>
     */
    public static function choice(string $itemId, string $action, ?int $session): array
    {
        return match ($action) {
            // Without a session time there is nothing to snooze against; an
            // empty value simply never matches, so the item stays visible.
            'snooze' => [self::SNOOZE . $itemId => $session !== null ? (string) $session : ''],
            'ignore' => [self::IGNORE . $itemId => '1', self::SNOOZE . $itemId => ''],
            'restore' => [self::IGNORE . $itemId => '0', self::SNOOZE . $itemId => ''],
            default => [],
        };
    }

    /**
     * Validate one item from a module; anything malformed is dropped rather
     * than passed to the browser.
     *
     * @return array<string,string>|null
     */
    private static function clean(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $id = is_string($item['id'] ?? null) ? $item['id'] : '';
        if (!preg_match('/^[a-z0-9-]+:[a-z0-9_.-]+$/', $id)) {
            return null;
        }
        $text = static fn (string $key, int $max): string => mb_substr(trim((string) (is_scalar($item[$key] ?? null) ? $item[$key] : '')), 0, $max);
        $title = $text('title', 120);
        if ($title === '') {
            return null;
        }
        $href = $text('href', 300);
        return [
            'id' => $id,
            'module' => $text('module', 60) ?: strstr($id, ':', true),
            'title' => $title,
            'description' => $text('description', 400),
            'state' => in_array($item['state'] ?? null, self::STATES, true) ? (string) $item['state'] : 'missing',
            'level' => in_array($item['level'] ?? null, self::LEVELS, true) ? (string) $item['level'] : 'recommended',
            // Same-document paths only: this is rendered as a link in the panel.
            'href' => str_starts_with($href, '/') && !str_starts_with($href, '//') ? $href : '/einstellungen',
        ];
    }
}
