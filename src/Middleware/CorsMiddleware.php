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
    /**
     * @param (callable(string): bool)|list<string> $allowedOrigins A PREDICATE,
     *        not a list, because the allow-list is editable in the panel now
     *        ({@see \Tds\CoreFrontendApi\Service\CorsConfig}). Two things follow
     *        from that and both are load-bearing:
     *
     *        A list captured at boot would only change on the next deploy — the
     *        setting would appear to save and quietly do nothing.
     *
     *        And asking for the whole list would mean resolving the stored layer
     *        on every request. This middleware is OUTERMOST: it runs before
     *        anything else, on every call including preflights, so that would
     *        put a database connection attempt in front of the entire API — on a
     *        host whose DB is down or firewalled, not a slow request but a hung
     *        one. A predicate lets the caller answer from the coded baseline
     *        first and reach for the database only for an origin nothing else
     *        covers. A plain list is still accepted, for tests.
     */
    public function __construct(private readonly mixed $allowedOrigins)
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

    private function allows(string $origin): bool
    {
        if (!is_callable($this->allowedOrigins)) {
            return in_array($origin, $this->allowedOrigins, true);
        }
        try {
            return (bool) ($this->allowedOrigins)($origin);
        } catch (\Throwable) {
            // A CORS policy that throws would take the whole API down on a
            // database hiccup. Degrade to "not allowed" rather than to a 500 —
            // the request still answers, just without the header.
            return false;
        }
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        if ($origin !== '' && $this->allows($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $response
            // PATCH is not optional: the contact inbox triages with
            // `PATCH /contact/messages/{id}`, and every panel call is
            // cross-origin (static product host → api.tracht-digital.de). A
            // method missing here fails at the preflight, i.e. the button does
            // nothing and the network tab shows an OPTIONS, not the PATCH.
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            // `X-Act-As-Company` is the current spelling; the old one stays for
            // one release. A header missing from this list fails the PREFLIGHT,
            // so the request is never sent at all — the control just looks dead,
            // with an OPTIONS where you are looking for the real call.
            // X-TDS-Site-Key is listed although the build-time callers that use
            // it are server-side and never preflight. It is here so that the
            // day a browser call needs it, the request is not rejected at the
            // preflight — a failure whose symptom is an OPTIONS where you are
            // looking for the real request, and nothing in any log.
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Act-As-Company, X-Act-As-Customer, X-Chat-Token, X-TDS-Site-Key')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
