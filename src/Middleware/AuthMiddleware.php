<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Middleware;

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tds\CoreFrontendApi\Auth\TokenVerifier;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\CoreFrontendApi\Support\JwtUserContext;
use Tds\Frontend\Contract\UserContext;

/**
 * Populates the request principal for every request, then hands off. It does
 * NOT gate — routes/modules enforce their own auth via the resolved
 * {@see UserContext} (a RequirePermission middleware or in-action checks). This
 * keeps auth in ONE place: modules read the context, never re-verify a token.
 *
 * The token comes from the `Authorization: Bearer` header or the cross-subdomain
 * `tds_session` cookie tds-auth-api sets (so the static panels authenticate with
 * `credentials: 'include'`). A missing/invalid token → anonymous context.
 *
 * It rebinds `UserContext::class` on the shared container each request. Safe in
 * the in-process model (one request per PHP-FPM worker at a time); the binding
 * is always set (Jwt or Anonymous) so no request inherits another's principal.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'tds_session';
    /**
     * The "act as this company" header, current spelling first.
     *
     * Both are read for one release: the panel and the extensions ship
     * independently of this service, so a build that still sends the old name
     * must keep working — otherwise an admin's company view silently falls back
     * to "no company" and every scoped list comes back empty, with no error
     * anywhere. Drop `X-Act-As-Customer` together with the rest of the aliases.
     */
    private const ACT_AS_HEADERS = ['X-Act-As-Company', 'X-Act-As-Customer'];

    public function __construct(
        private readonly Container $container,
        private readonly ?TokenVerifier $verifier,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = new AnonymousUserContext();

        $token = $this->extractToken($request);
        if ($token !== null && $this->verifier !== null) {
            try {
                $claims = $this->verifier->verify($token);
                $context = new JwtUserContext($claims, self::actAs($request));
            } catch (\Throwable) {
                // Invalid/expired token → stay anonymous (routes decide the 401).
            }
        }

        $this->container->set(UserContext::class, $context);
        return $handler->handle($request);
    }

    /** The first act-as header that carries a value, current spelling first. */
    private static function actAs(ServerRequestInterface $request): string
    {
        foreach (self::ACT_AS_HEADERS as $header) {
            $value = trim($request->getHeaderLine($header));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $cookie = $request->getCookieParams()[self::COOKIE_NAME] ?? null;
        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
