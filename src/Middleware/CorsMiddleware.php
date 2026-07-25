<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Owns CORS for the base API + every mounted module (one app, one origin
 * policy). Extensions never add their own CORS. MUST be added AFTER
 * addRoutingMiddleware() so it runs outermost (Slim middleware is LIFO) and can
 * short-circuit an OPTIONS preflight before routing 405s it. See PreflightTest.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @param string[] $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders(new Response(204), $origin);
        }

        return $this->withCorsHeaders($handler->handle($request), $origin);
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        if ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Act-As-Customer, X-Chat-Token')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
