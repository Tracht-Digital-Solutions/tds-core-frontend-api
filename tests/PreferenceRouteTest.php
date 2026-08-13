<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;
use Tds\CoreFrontendApi\Support\PreferenceWhitelist;

/**
 * `GET|PUT /me/preferences` — the per-user theme/locale/notification store.
 *
 * Own file: PHPUnit's directory loader only picks up the class whose name
 * matches the file, so a second TestCase beside another one never runs.
 *
 * The route gate is exercised against the REAL composed app; the filtering
 * rule is exercised directly, which is why it lives in its own class rather
 * than inside the route closure.
 */
final class PreferenceRouteTest extends TestCase
{
    public function testAnonymousIsRejectedOnBothVerbs(): void
    {
        // 401 rather than 200-with-defaults: these are per USER, and answering
        // anything to a logged-out caller would mean the panel silently
        // persisted one person's theme against nobody. 401 (not 404) also
        // proves both routes are mounted.
        $app = Bootstrap::createApp(dirname(__DIR__));

        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/me/preferences'),
        )->getStatusCode());

        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/me/preferences')
                ->withParsedBody(['preferences' => ['theme' => 'dark']]),
        )->getStatusCode());
    }

    public function testTheGateRunsBeforeTheBodyIsValidated(): void
    {
        // A malformed body from an anonymous caller must still be 401, not 422
        // — otherwise the endpoint reports on its own shape to someone who is
        // not allowed to use it.
        $app = Bootstrap::createApp(dirname(__DIR__));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/me/preferences')
                ->withParsedBody(['preferences' => 'not-an-object']),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testFilterKeepsOnlyKnownKeysAndValues(): void
    {
        $accepted = PreferenceWhitelist::filter([
            'theme' => 'dark',
            'locale' => 'en',
            // Unknown key — dropped, not fatal. A newer panel writing a
            // preference this backend has not heard of must not lose the
            // whole save.
            'density' => 'compact',
            // Known key, invalid value.
            'notify_toast' => 'maybe',
        ]);

        self::assertSame(['theme' => 'dark', 'locale' => 'en'], $accepted);
    }

    public function testFilterIsAPartialWriteset(): void
    {
        // Keys not sent must not come back, or saving the Darstellung tab
        // would clear the notification toggles it never rendered.
        self::assertSame(['theme' => 'light'], PreferenceWhitelist::filter(['theme' => 'light']));
        self::assertSame([], PreferenceWhitelist::filter([]));
    }

    public function testFilterAcceptsSystemAsARealStoredChoice(): void
    {
        // The browser stores "follow the OS" as the ABSENCE of a value; the
        // server must be able to hold it as a choice, or it cannot be told
        // apart from "this user has never picked one".
        self::assertSame(['theme' => 'system'], PreferenceWhitelist::filter(['theme' => 'system']));
    }

    public function testFilterHandlesBooleanTogglesWithoutDroppingFalse(): void
    {
        // JSON `false` casts to "" and would fail the whitelist, silently
        // turning "switch this off" into "change nothing" — the worst shape
        // for a notification toggle.
        self::assertSame(
            ['notify_toast' => '0', 'notify_email' => '1'],
            PreferenceWhitelist::filter(['notify_toast' => false, 'notify_email' => true]),
        );
    }

    public function testFilterRejectsNonScalars(): void
    {
        self::assertSame([], PreferenceWhitelist::filter([
            'theme' => ['dark'],
            'locale' => null,
        ]));
    }

    public function testEveryWhitelistedThemeMatchesTheSharedLibrary(): void
    {
        // tds-shared's THEME_PREFERENCES is the source of truth for the panel;
        // a value accepted here but unknown there would be stored and then
        // silently ignored on every device.
        self::assertSame(['light', 'dark', 'system'], PreferenceWhitelist::VALUES['theme']);
    }
}
