<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Auth\JwksClient;

/**
 * The kernel's auth boundary.
 *
 * Every composed module trusts `UserContext` and never re-verifies a token, so
 * this class is the single place where "is this caller who they say they are"
 * is decided for the whole frontend API.
 *
 * The half that is easy to get wrong is the CACHE. The JWKS is fetched from
 * tds-auth-api over the network on a hot path, so it is written to disk — and
 * the two failure modes point in opposite directions: too little caching
 * hammers the auth API on every single request, while too much keeps trusting
 * a key that has been rotated out.
 */
final class JwksClientTest extends TestCase
{
    private string $cacheDir;
    private string $privateKey;
    /** @var array<string,mixed> */
    private array $jwks;
    /** @var list<array<string,mixed>> Guzzle transaction log, written by reference. */
    private array $history = [];

    protected function setUp(): void
    {
        $this->history = [];
        $this->cacheDir = sys_get_temp_dir() . '/tds-jwks-' . bin2hex(random_bytes(6));

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            self::markTestSkipped('openssl_pkey_new unavailable: ' . (openssl_error_string() ?: 'unknown'));
        }
        openssl_pkey_export($res, $priv);
        $this->privateKey = (string) $priv;

        $details = openssl_pkey_get_details($res);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64u($details['rsa']['n']),
            'e' => self::b64u($details['rsa']['e']),
        ]]];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cacheDir);
        }
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @param list<Response|\Throwable> $queue */
    private function client(array $queue, int $ttl = 300): JwksClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(\GuzzleHttp\Middleware::history($this->history));

        return new JwksClient(
            new Client(['handler' => $stack]),
            'https://auth.example.de/.well-known/jwks.json',
            $this->cacheDir,
            $ttl,
        );
    }

    private function jwksResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->jwks, JSON_THROW_ON_ERROR));
    }

    private function token(array $claims = [], ?int $exp = null): string
    {
        return JWT::encode(
            ['sub' => '1', 'iat' => time(), 'exp' => $exp ?? time() + 3600] + $claims,
            $this->privateKey,
            'RS256',
            'test-key',
        );
    }

    // --- verification -----------------------------------------------------

    public function test_verifies_a_token_signed_by_the_published_key(): void
    {
        $client = $this->client([$this->jwksResponse()]);
        $claims = $client->verify($this->token(['email' => 'kunde@example.de', 'admin' => true]));

        self::assertSame('kunde@example.de', $claims['email']);
        self::assertTrue($claims['admin']);
    }

    public function test_returns_the_claims_the_modules_read(): void
    {
        // userId / permissions / companies drive every module's RBAC.
        $client = $this->client([$this->jwksResponse()]);
        $claims = $client->verify($this->token([
            'userId' => 42,
            'permissions' => ['tickets:read'],
            'companies' => [['id' => 7, 'permissions' => ['tickets:read']]],
        ]));

        self::assertSame(42, $claims['userId']);
        self::assertSame(['tickets:read'], (array) $claims['permissions']);
    }

    public function test_REJECTS_a_token_signed_by_a_different_key(): void
    {
        // The whole point of the boundary: a self-signed token must not pass.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPriv);
        $forged = JWT::encode(['sub' => '1', 'exp' => time() + 3600], (string) $otherPriv, 'RS256', 'test-key');

        $client = $this->client([$this->jwksResponse()]);
        $this->expectException(\Throwable::class);
        $client->verify($forged);
    }

    public function test_rejects_an_expired_token(): void
    {
        $client = $this->client([$this->jwksResponse()]);
        $this->expectException(\Throwable::class);
        $client->verify($this->token([], time() - 60));
    }

    public function test_rejects_a_malformed_token(): void
    {
        $client = $this->client([$this->jwksResponse()]);
        $this->expectException(\Throwable::class);
        $client->verify('not.a.jwt');
    }

    public function test_rejects_an_empty_token(): void
    {
        $client = $this->client([$this->jwksResponse()]);
        $this->expectException(\Throwable::class);
        $client->verify('');
    }

    // --- the disk cache ---------------------------------------------------

    public function test_CACHES_the_jwks_so_a_second_verify_makes_no_request(): void
    {
        // Without this the auth API is called on every single request that
        // carries a token — which is all of them.
        $client = $this->client([$this->jwksResponse()]);
        $client->verify($this->token());
        $client->verify($this->token());

        self::assertCount(1, $this->history, 'the JWKS should be fetched once, then served from disk');
    }

    public function test_writes_the_cache_file(): void
    {
        $client = $this->client([$this->jwksResponse()]);
        $client->verify($this->token());

        self::assertFileExists($this->cacheDir . '/jwks.json');
    }

    public function test_creates_the_cache_directory_when_missing(): void
    {
        self::assertDirectoryDoesNotExist($this->cacheDir);
        $client = $this->client([$this->jwksResponse()]);
        $client->verify($this->token());

        self::assertDirectoryExists($this->cacheDir);
    }

    public function test_REFETCHES_once_the_cache_is_older_than_the_ttl(): void
    {
        // A rotated-out key must stop being trusted; an unbounded cache would
        // keep accepting tokens signed by a key the auth API has retired.
        $client = $this->client([$this->jwksResponse(), $this->jwksResponse()], 60);
        $client->verify($this->token());

        touch($this->cacheDir . '/jwks.json', time() - 3600);
        $client->verify($this->token());

        self::assertCount(2, $this->history, 'a stale cache must be refetched');
    }

    public function test_serves_from_a_cache_that_is_still_within_the_ttl(): void
    {
        $client = $this->client([$this->jwksResponse()], 3600);
        $client->verify($this->token());
        touch($this->cacheDir . '/jwks.json', time() - 60);
        $client->verify($this->token());

        self::assertCount(1, $this->history);
    }

    public function test_refetches_when_the_cache_file_is_corrupt(): void
    {
        // A truncated write (disk full, killed process) must not brick auth.
        @mkdir($this->cacheDir, 0755, true);
        file_put_contents($this->cacheDir . '/jwks.json', '{ this is not json');

        $client = $this->client([$this->jwksResponse()]);
        $claims = $client->verify($this->token());

        self::assertCount(1, $this->history);
        self::assertSame('1', $claims['sub']);
    }

    public function test_refetches_when_the_cached_json_is_not_an_object(): void
    {
        @mkdir($this->cacheDir, 0755, true);
        file_put_contents($this->cacheDir . '/jwks.json', '"a string"');

        $client = $this->client([$this->jwksResponse()]);
        $client->verify($this->token());

        self::assertCount(1, $this->history);
    }

    public function test_uses_a_valid_cache_written_by_an_earlier_process(): void
    {
        // A warm cache from a previous request must be honoured, not ignored.
        @mkdir($this->cacheDir, 0755, true);
        file_put_contents($this->cacheDir . '/jwks.json', json_encode($this->jwks, JSON_THROW_ON_ERROR));

        $client = $this->client([]);
        $claims = $client->verify($this->token());

        self::assertCount(0, $this->history, 'no HTTP call should be made with a warm cache');
        self::assertSame('1', $claims['sub']);
    }

    // --- a broken auth API ------------------------------------------------

    public function test_rejects_a_jwks_response_without_a_keys_member(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['oops' => true], JSON_THROW_ON_ERROR))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWKS response');
        $client->verify($this->token());
    }

    public function test_names_the_url_it_could_not_read(): void
    {
        // The operator reading this log has several services in play.
        $client = $this->client([new Response(200, [], 'not json at all')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('https://auth.example.de/.well-known/jwks.json');
        $client->verify($this->token());
    }

    public function test_does_not_cache_an_invalid_jwks_response(): void
    {
        // Caching garbage would keep auth broken for the whole TTL.
        $client = $this->client([new Response(200, [], 'not json at all')]);

        try {
            $client->verify($this->token());
        } catch (\Throwable) {
            // expected
        }

        self::assertFileDoesNotExist($this->cacheDir . '/jwks.json');
    }

    public function test_propagates_a_transport_failure(): void
    {
        // Better a 500 than silently treating the caller as anonymous here;
        // the middleware above decides what an unverifiable token means.
        $client = $this->client([new \GuzzleHttp\Exception\ConnectException(
            'connection refused',
            new \GuzzleHttp\Psr7\Request('GET', 'https://auth.example.de/.well-known/jwks.json'),
        )]);

        $this->expectException(\Throwable::class);
        $client->verify($this->token());
    }
}
