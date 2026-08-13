<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Support;

/**
 * What `PUT /me/preferences` is allowed to store.
 *
 * A closed key AND value set. These values are rendered straight back into
 * `<html data-theme>` and `<html lang>` by the panel shell, and the store is
 * shared with whatever the panel ships next — an open string column feeding a
 * DOM attribute only looks harmless until someone renders it somewhere else.
 *
 * Its own class rather than a closure inside `Bootstrap::createApp()` because
 * this rule is the interesting part of the endpoint: buried in the route it
 * could only be exercised with a live token and a database, which is exactly
 * how filtering logic ends up untested.
 */
final class PreferenceWhitelist
{
    /**
     * key => allowed values.
     *
     * `theme` mirrors tds-shared's `THEME_PREFERENCES`. It includes `"system"`
     * even though the BROWSER stores that as the absence of a localStorage
     * value: the server has to persist "follow the OS" as a real choice, or it
     * cannot be told apart from "this user has never chosen".
     */
    public const VALUES = [
        'theme' => ['light', 'dark', 'system'],
        'locale' => ['de', 'en'],
        'notify_toast' => ['0', '1'],
        'notify_email' => ['0', '1'],
    ];

    /**
     * Keep only recognised key/value pairs.
     *
     * Unknown keys and invalid values are dropped **silently** rather than
     * rejected — the same convention as the dashboard layout, and for the same
     * reason: a newer panel writing a preference this backend has not heard of
     * must not lose the whole save. The store is a key/value table precisely so
     * an unknown key is inert rather than fatal.
     *
     * The result is a PARTIAL write: a key that was not sent is not returned,
     * so saving the Darstellung tab cannot clear notification toggles it never
     * rendered.
     *
     * @param array<array-key,mixed> $raw
     * @return array<string,string>
     */
    public static function filter(array $raw): array
    {
        $accepted = [];
        foreach (self::VALUES as $key => $allowed) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            // Booleans arrive as real booleans from JSON; `(string) true` is
            // "1" and `(string) false` is "" — so the notify_* toggles work
            // whether the client sends 1/0, "1"/"0" or true/false, and false
            // is not silently dropped.
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (in_array($value, $allowed, true)) {
                $accepted[$key] = $value;
            }
        }
        return $accepted;
    }
}
