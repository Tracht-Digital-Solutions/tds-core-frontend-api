<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;

/**
 * `GET /me/notifications` on the REAL composed app.
 *
 * Own file: PHPUnit's directory loader only picks up the class whose name
 * matches the file, so a second TestCase beside another one never runs.
 */
final class NotificationRouteTest extends TestCase
{
    public function testAnonymousIsRejected(): void
    {
        // The feed is per principal. Answering 200 + an empty list would let a
        // logged-out tab poll forever; 401 (rather than 404) also proves the
        // route is mounted at all.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/me/notifications'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAGarbageCursorIsNotAClientError(): void
    {
        // The shell has no way to repair a cursor it did not author, so a 4xx
        // would stall its poller permanently. Still 401 here (anonymous), i.e.
        // the cursor never became the reason for the failure.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/me/notifications?since=!!!!'),
        );

        self::assertSame(401, $response->getStatusCode());
    }
}
