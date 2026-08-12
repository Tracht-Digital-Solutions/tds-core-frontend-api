<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Support;

/**
 * The opaque cursor of `GET /me/notifications`.
 *
 * A map of module-id → that module's own cursor, base64url-encoded JSON. Opaque
 * on purpose: the browser only ever echoes it back, so the shape stays free to
 * change, and a module never has to coordinate its cursor with anyone else's.
 *
 * Every decode failure — absent, truncated, not base64, not JSON, JSON of the
 * wrong shape — collapses to the SAME result: an empty map, i.e. "first call".
 * That is deliberate. A first call yields the cursor and no items, so the worst
 * a corrupt cursor can do is cost the reader one poll of notifications. The
 * alternative (a 4xx) would stall the shell's poller on a value it has no way
 * to repair.
 */
final class NotificationCursor
{
    /**
     * @param string|null $raw the `since` query parameter, if any
     * @return array<string,string> module id → cursor
     */
    public static function decode(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $id => $cursor) {
            // Scalars only — a nested array here would blow up the string cast
            // in the source that receives it.
            if (is_string($id) && (is_string($cursor) || is_int($cursor))) {
                $out[$id] = (string) $cursor;
            }
        }
        return $out;
    }

    /** @param array<string,string> $cursors */
    public static function encode(array $cursors): string
    {
        $json = json_encode($cursors, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
