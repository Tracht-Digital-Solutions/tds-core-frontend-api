<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CoreFrontendApi\Auth\TokenVerifier;
use Tds\CoreFrontendApi\Middleware\AuthMiddleware;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\Frontend\Contract\UserContext;

/**
 * AuthMiddleware populates the container's UserContext each request (Jwt when a
 * valid token is presented, anonymous otherwise) and never gates. Uses a stub
 * verifier so no live JWKS is needed.
 */
final class AuthMiddlewareTest extends TestCase
{
    public function testBindsJwtContextForAValidBearerToken(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());

        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                return ['admin' => true, 'uid' => 1];
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/x')
            ->withHeader('Authorization', 'Bearer whatever');

        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        $ctx = $container->get(UserContext::class);
        self::assertTrue($ctx->isAuthenticated());
        self::assertTrue($ctx->isAdmin());
    }

    public function testStaysAnonymousWithoutAToken(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());
        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                throw new \RuntimeException('should not be called');
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        self::assertFalse($container->get(UserContext::class)->isAuthenticated());
    }

    public function testInvalidTokenFallsBackToAnonymous(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());
        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                throw new \RuntimeException('bad signature');
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/x')
            ->withHeader('Authorization', 'Bearer bad');
        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        self::assertFalse($container->get(UserContext::class)->isAuthenticated());
    }

    /**
     * The act-as header decides which tenant a request reads and writes, so
     * both spellings have to work while the rename settles: the panel and the
     * thirteen extensions ship independently of this service, and a build still
     * sending the old name would otherwise fall back to "no company" — every
     * scoped list empty, no error anywhere.
     */
    public function testAcceptsEitherActAsHeaderSpelling(): void
    {
        foreach (['X-Act-As-Company', 'X-Act-As-Customer'] as $header) {
            $container = new Container();
            $container->set(UserContext::class, static fn () => new AnonymousUserContext());

            $verifier = new class implements TokenVerifier {
                public function verify(string $jwt): array
                {
                    return [
                        'admin' => false,
                        'uid' => 1,
                        'companies' => [
                            ['id' => 3, 'permissions' => []],
                            ['id' => 9, 'permissions' => []],
                        ],
                    ];
                }
            };

            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/x')
                ->withHeader('Authorization', 'Bearer whatever')
                ->withHeader($header, '9');

            (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

            self::assertSame(
                9,
                $container->get(UserContext::class)->activeCompanyId(),
                "{$header} was ignored",
            );
        }
    }

    public function testPrefersTheCurrentHeaderWhenBothAreSent(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());

        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                return [
                    'admin' => false,
                    'uid' => 1,
                    'companies' => [
                        ['id' => 3, 'permissions' => []],
                        ['id' => 9, 'permissions' => []],
                    ],
                ];
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/x')
            ->withHeader('Authorization', 'Bearer whatever')
            ->withHeader('X-Act-As-Company', '9')
            ->withHeader('X-Act-As-Customer', '3');

        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        self::assertSame(9, $container->get(UserContext::class)->activeCompanyId());
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
