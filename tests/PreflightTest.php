<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;

/**
 * Regression guard for the Slim LIFO CORS ordering trap. An OPTIONS preflight
 * must be short-circuited by CorsMiddleware (204 + CORS headers), NOT 405'd by
 * the routing middleware. This runs through the REAL Bootstrap app — unit-
 * testing the middleware in isolation cannot catch the ordering mistake.
 */
final class PreflightTest extends TestCase
{
    public function testPreflightIsAnsweredWithCorsHeaders(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://management.tracht-digital.de';
        $app = Bootstrap::createApp(dirname(__DIR__));

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/admin/permissions')
            ->withHeader('Origin', 'https://management.tracht-digital.de')
            ->withHeader('Access-Control-Request-Method', 'GET');
        $response = $app->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'https://management.tracht-digital.de',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
        );
        self::assertStringContainsString('OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));

        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }

    /**
     * @dataProvider methodsTheFrontendsActuallyUse
     */
    public function testEveryMethodThePanelUsesIsAllowed(string $method): void
    {
        // PATCH was missing for months, and it fails in the most confusing way
        // available: the preflight is rejected, so the browser never sends the
        // request. The button appears dead and the network tab shows an OPTIONS
        // where you are looking for a PATCH. The contact inbox's triage
        // ("Erledigt"/"Spam") is a PATCH, and every panel call is cross-origin.
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://management.tracht-digital.de';
        $app = Bootstrap::createApp(dirname(__DIR__));

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/contact/messages/1')
            ->withHeader('Origin', 'https://management.tracht-digital.de')
            ->withHeader('Access-Control-Request-Method', $method);
        $response = $app->handle($request);

        self::assertStringContainsString(
            $method,
            $response->getHeaderLine('Access-Control-Allow-Methods'),
        );

        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }

    /** @return array<string, array{string}> */
    public static function methodsTheFrontendsActuallyUse(): array
    {
        return [
            'GET' => ['GET'],
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function testTheApexAndWwwLandingpageMayBothPostTheContactForm(): void
    {
        // The canonical site is the apex, but a visitor who arrives on `www.`
        // posts from an origin the browser will not accept a response for
        // unless it is listed — and a missing Access-Control-Allow-Origin is
        // silent: the form just shows its generic "try again later".
        foreach (['https://tracht-digital.de', 'https://www.tracht-digital.de'] as $origin) {
            $app = Bootstrap::createApp(dirname(__DIR__));
            $request = (new ServerRequestFactory())
                ->createServerRequest('OPTIONS', '/contact')
                ->withHeader('Origin', $origin)
                ->withHeader('Access-Control-Request-Method', 'POST');
            $response = $app->handle($request);

            self::assertSame($origin, $response->getHeaderLine('Access-Control-Allow-Origin'));
        }
    }
}
