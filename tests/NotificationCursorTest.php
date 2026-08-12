<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Support\NotificationCursor;

/**
 * The cursor codec — where a bad value would otherwise reach a module.
 *
 * NB: its own file on purpose. PHPUnit's directory loader only picks up the
 * class whose NAME matches the file, so a second TestCase living beside another
 * one is silently never run — it reports green without executing.
 */
final class NotificationCursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $cursors = ['contact-tickets' => '42', 'tickets' => '7'];
        self::assertSame($cursors, NotificationCursor::decode(NotificationCursor::encode($cursors)));
    }

    public function testEncodesUrlSafeAndUnpadded(): void
    {
        // It travels as a query parameter; `+`, `/` and `=` would each need
        // escaping somewhere along the way.
        $encoded = NotificationCursor::encode(['a' => str_repeat('x', 40)]);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    /** @return array<string, array{string|null}> */
    public static function garbage(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'not base64' => ['!!!!'],
            'base64 of not-json' => [base64_encode('nope')],
            'json of a scalar' => [base64_encode('42')],
        ];
    }

    /** @dataProvider garbage */
    public function testEveryBadCursorDecodesToFirstCall(?string $raw): void
    {
        // All of these collapse to "no cursor" rather than an error: a first
        // call costs the reader one poll, where a 4xx would stall the shell's
        // poller on a value it has no way to repair.
        self::assertSame([], NotificationCursor::decode($raw));
    }

    public function testDropsNonScalarCursorValues(): void
    {
        // A nested value would fatal on the string cast in the receiving source.
        $raw = base64_encode(json_encode(['ok' => '5', 'bad' => ['nested']], JSON_THROW_ON_ERROR));
        self::assertSame(['ok' => '5'], NotificationCursor::decode($raw));
    }

    public function testAcceptsIntegerCursors(): void
    {
        // json_decode yields an int for an unquoted number; a source that wrote
        // `'cursor' => 42` must not lose it on the round trip.
        $raw = base64_encode(json_encode(['a' => 42], JSON_THROW_ON_ERROR));
        self::assertSame(['a' => '42'], NotificationCursor::decode($raw));
    }
}
