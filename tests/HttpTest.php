<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebhookAdmin\Client;
use WebhookAdmin\Exception\ApiException;
use WebhookAdmin\Exception\AuthenticationException;
use WebhookAdmin\Exception\ConflictException;
use WebhookAdmin\Exception\ConnectionException;
use WebhookAdmin\Exception\NotFoundException;
use WebhookAdmin\Exception\PermissionException;
use WebhookAdmin\Exception\PlanLimitException;
use WebhookAdmin\Exception\RateLimitException;
use WebhookAdmin\Exception\TimeoutException;
use WebhookAdmin\Exception\ValidationException;
use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Http\HttpClient;
use WebhookAdmin\Http\Response;
use WebhookAdmin\Page;
use WebhookAdmin\Version;

/** Retries, timeouts, error types, pagination and configuration. */
final class HttpTest extends TestCase
{
    private const EMPTY_PAGE = ['items' => [], 'next_cursor' => null];

    protected function tearDown(): void
    {
        putenv(Client::API_KEY_ENV);
    }

    private static function send(Client $c, array $options = []): array
    {
        return $c->messages->send(['consumer' => 'c', 'event_type' => 'e', 'payload' => 1], $options);
    }

    /** @return array{0: ?\Throwable, 1: mixed} */
    private static function attempt(callable $fn): array
    {
        try {
            return [null, $fn()];
        } catch (\Throwable $e) {
            return [$e, null];
        }
    }

    public function testHeaders(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::EMPTY_PAGE));
        FakeTransport::client($t)->consumers->list();
        $h = $t->last()->headers;
        self::assertSame('Bearer sk_test_abc', $h['authorization']);
        self::assertSame('application/json', $h['accept']);
        self::assertSame('webhookadmin-php/' . Version::VERSION, $h['user-agent']);
        self::assertArrayNotHasKey('content-type', $h);
        self::assertNull($t->last()->body);
        self::assertSame(30.0, $t->last()->timeout);
    }

    public function testUserAgentIsParsedByTheWorker(): void
    {
        // apps/worker/src/auth.ts の sdkOf と同じ正規表現
        self::assertMatchesRegularExpression('/(?:^|\s)(webhookadmin-[a-z]+)\/(\d+\.\d+\.\d+)(?=\s|$)/', Version::USER_AGENT);
    }

    public function testVersionMatchesChangelog(): void
    {
        self::assertStringContainsString('## ' . Version::VERSION, (string) file_get_contents(__DIR__ . '/../CHANGELOG.md'));
    }

    public function testApiKeyFromEnv(): void
    {
        putenv(Client::API_KEY_ENV . '= sk_live_env ');
        $t = new FakeTransport(FakeTransport::json(200, self::EMPTY_PAGE));
        (new Client(null, ['transport' => $t]))->consumers->list();
        self::assertSame('Bearer sk_live_env', $t->last()->headers['authorization']);
    }

    public function testMissingApiKey(): void
    {
        putenv(Client::API_KEY_ENV . '=  ');
        [$e] = self::attempt(fn () => new Client());
        self::assertInstanceOf(WebhookAdminException::class, $e);
        self::assertStringContainsString(Client::API_KEY_ENV, $e->getMessage());
        [$e2] = self::attempt(fn () => new Client(''));
        self::assertInstanceOf(WebhookAdminException::class, $e2);
    }

    public function testBaseUrlAndTimeout(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::EMPTY_PAGE));
        $c = new Client('sk_test_x', ['transport' => $t]);
        self::assertSame('https://api.webhookadmin.com', $c->baseUrl);
        $c->consumers->list();
        self::assertSame('https://api.webhookadmin.com/v1/consumers', $t->last()->url);
        self::assertSame('http://localhost:8787', (new Client('sk_test_x', ['base_url' => 'http://localhost:8787//']))->baseUrl);
        (new Client('sk_test_x', ['transport' => $t, 'timeout' => 5]))->consumers->list();
        self::assertSame(5.0, $t->last()->timeout);
        (new Client('sk_test_x', ['transport' => $t, 'timeout' => 5]))->consumers->list([], ['timeout' => 1.5]);
        self::assertSame(1.5, $t->last()->timeout);
    }

    /** @return list<array{int, string, class-string}> */
    public static function statuses(): array
    {
        return [
            [400, 'bad_json', ValidationException::class],
            [401, 'unauthorized', AuthenticationException::class],
            [402, 'plan_limit', PlanLimitException::class],
            [403, 'forbidden', PermissionException::class],
            [404, 'not_found', NotFoundException::class],
            [409, 'conflict', ConflictException::class],
            [413, 'too_large', ValidationException::class],
            [422, 'invalid', ValidationException::class],
            [418, 'teapot', ApiException::class],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusToClass(int $status, string $code, string $class): void
    {
        $t = new FakeTransport(FakeTransport::json($status, ['error' => $code, 'message' => "m $status"], ['cf-ray' => 'ray-1']));
        [$e] = self::attempt(fn () => self::send(FakeTransport::client($t)));
        self::assertInstanceOf($class, $e);
        self::assertInstanceOf(WebhookAdminException::class, $e);
        self::assertSame([$status, $code, "m $status", 'ray-1', null], [$e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getRequestId(), $e->getFields()]);
        self::assertSame([$status, $code], [$e->status, $e->errorCode]);
        self::assertCount(1, $t->calls);
    }

    public function testFieldsAndRequestId(): void
    {
        $t = new FakeTransport(FakeTransport::json(422, ['error' => 'invalid', 'message' => 'bad', 'fields' => ['url' => 'https only', 'n' => 1, 'x' => ['a']]], ['x-request-id' => 'req_1', 'cf-ray' => 'r']));
        [$e] = self::attempt(fn () => FakeTransport::client($t)->endpoints->create(['consumer_id' => 'c', 'url' => 'http://x']));
        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame(['url' => 'https only', 'n' => '1', 'x' => '["a"]'], $e->fields);
        self::assertSame('req_1', $e->requestId);
    }

    public function testNonJsonErrorBodies(): void
    {
        $t = new FakeTransport(new Response(502, [], '<html>bad gateway</html>'), new Response(400, [], 'null'), new Response(500, ['cf-ray' => ''], '"oops"'));
        $c = FakeTransport::client($t, ['max_retries' => 0]);
        foreach ([[502, ApiException::class], [400, ValidationException::class], [500, ApiException::class]] as [$status, $class]) {
            [$e] = self::attempt(fn () => $c->messages->get('m'));
            self::assertInstanceOf($class, $e);
            self::assertSame([$status, null, "HTTP $status", null], [$e->status, $e->errorCode, $e->getMessage(), $e->requestId]);
        }
    }

    public function testInvalidJsonIn2xx(): void
    {
        $t = new FakeTransport(new Response(200, ['CF-Ray' => 'r2'], '{oops'));
        [$e] = self::attempt(fn () => FakeTransport::client($t)->messages->get('m'));
        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame([200, 'r2'], [$e->status, $e->requestId]);
        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        self::assertCount(1, $t->calls);
    }

    public function testOtherTransportErrorsAreConnectionErrors(): void
    {
        $boom = new \RuntimeException('socket hang up');
        $t = new FakeTransport($boom);
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 0])->messages->get('m'));
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertNotInstanceOf(TimeoutException::class, $e);
        self::assertSame($boom, $e->getPrevious());
        self::assertSame('Connection error: socket hang up', $e->getMessage());
        self::assertNull($e->status);
    }

    public function testSdkExceptionsFromATransportAreNotWrapped(): void
    {
        $own = new PermissionException('custom');
        $t = new FakeTransport($own);
        [$e] = self::attempt(fn () => FakeTransport::client($t)->messages->get('m'));
        self::assertSame($own, $e);
        self::assertCount(1, $t->calls);
    }

    public function testConstructDirectly(): void
    {
        $e = new RateLimitException('slow');
        self::assertSame(['slow', null, null], [$e->getMessage(), $e->status, $e->retryAfter]);
        self::assertSame(0, $e->getCode());
    }

    public function test5xxWithBackoffThenSuccess(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(500, ['error' => 'internal', 'message' => 'x']), FakeTransport::json(503, []), FakeTransport::json(200, ['id' => 'msg_1']));
        self::assertSame(['id' => 'msg_1'], FakeTransport::client($t, [], $s)->messages->get('msg_1'));
        self::assertCount(3, $t->calls);
        self::assertSame([0.5, 1.0], $s->waits);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(500, ['error' => 'internal', 'message' => 'down']));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 3], $s)->messages->get('m'));
        self::assertInstanceOf(ApiException::class, $e);
        self::assertCount(4, $t->calls);
        self::assertSame([0.5, 1.0, 2.0], $s->waits);
    }

    public function testMaxRetriesPerRequest(): void
    {
        $t = new FakeTransport(FakeTransport::json(500, []));
        self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 5])->messages->get('m', ['max_retries' => 0]));
        self::assertCount(1, $t->calls);
    }

    public function testSendReusesTheIdempotencyKey(): void
    {
        $t = new FakeTransport(new ConnectionException('reset'), FakeTransport::json(502, []), FakeTransport::json(202, ['id' => 'msg_1', 'deliveries' => 1]));
        self::assertSame(['id' => 'msg_1', 'deliveries' => 1], self::send(FakeTransport::client($t)));
        $keys = array_unique(array_map(fn ($r) => $r->headers['idempotency-key'], $t->calls));
        self::assertCount(3, $t->calls);
        self::assertCount(1, $keys);
        self::assertMatchesRegularExpression('/^webhookadmin-php-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
        self::send(FakeTransport::client($t), ['idempotency_key' => 'invoice-1-paid']);
        self::assertSame('invoice-1-paid', $t->last()->headers['idempotency-key']);
    }

    public function testConnectionErrorsRetriedForSafeRequests(): void
    {
        $t = new FakeTransport(new \RuntimeException('ECONNRESET'), FakeTransport::json(200, self::EMPTY_PAGE));
        self::assertSame([], FakeTransport::client($t)->consumers->list()->items);
        self::assertCount(2, $t->calls);
    }

    public function testTimeoutRetriedForSafeRequests(): void
    {
        $t = new FakeTransport(new TimeoutException('Request timed out after 1 s'));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 1])->messages->get('m'));
        self::assertInstanceOf(TimeoutException::class, $e);
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertCount(2, $t->calls);
    }

    /** @return list<array{mixed}> */
    public static function unsafeFailures(): array
    {
        return [[FakeTransport::json(500, [])], [new \RuntimeException('ECONNRESET')], [new TimeoutException('t')]];
    }

    #[DataProvider('unsafeFailures')]
    public function testUnsafeRequestsAreNotRetried(mixed $reply): void
    {
        $t = new FakeTransport($reply);
        $c = FakeTransport::client($t);
        $ops = [
            fn () => $c->consumers->create(['external_id' => 'x']),
            fn () => $c->endpoints->create(['consumer_id' => 'c', 'url' => 'https://x']),
            fn () => $c->endpoints->rotateSecret('ep'),
            fn () => $c->endpoints->sendTest('ep'),
            fn () => $c->endpoints->recover('ep', ['since' => 1]),
            fn () => $c->deliveries->retry('d'),
            fn () => $c->eventTypes->create(['name' => 'x']),
        ];
        foreach ($ops as $op) {
            [$e] = self::attempt($op);
            self::assertInstanceOf(WebhookAdminException::class, $e);
        }
        self::assertCount(count($ops), $t->calls);
    }

    public function test4xxNotRetried(): void
    {
        foreach ([400, 401, 403, 404, 409, 422] as $s) {
            $t = new FakeTransport(FakeTransport::json($s, ['error' => 'x', 'message' => 'y']));
            self::attempt(fn () => FakeTransport::client($t)->messages->get('m'));
            self::assertCount(1, $t->calls);
        }
    }

    public function test429WaitsRetryAfter(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(429, ['error' => 'rate_limited', 'message' => 'slow'], ['retry-after' => '7']), FakeTransport::json(201, ['id' => 'con_1']));
        self::assertSame(['id' => 'con_1'], FakeTransport::client($t, [], $s)->consumers->create(['external_id' => 'x']));
        self::assertSame([7.0], $s->waits);
    }

    public function test429WaitsUntilRateLimitReset(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(429, ['error' => 'rate_limited', 'message' => 'x'], ['x-ratelimit-reset' => '1012']), FakeTransport::json(200, ['id' => 'm']));
        $c = FakeTransport::client($t, [], $s);
        $c->http->clock = static fn (): float => 1000.0;
        $c->messages->get('m');
        self::assertSame([12.0], $s->waits);
    }

    public function test429WithoutHeadersUsesBackoff(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(429, ['error' => 'rate_limited', 'message' => 'x']), FakeTransport::json(200, ['id' => 'm']));
        FakeTransport::client($t, [], $s)->messages->get('m');
        self::assertSame([0.5], $s->waits);
    }

    public function test429ThrownWithRetryAfter(): void
    {
        $t = new FakeTransport(FakeTransport::json(429, ['error' => 'rate_limited', 'message' => 'x'], ['retry-after' => '1']));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 1])->messages->get('m'));
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(1.0, $e->retryAfter);
        self::assertCount(2, $t->calls);
    }

    public function test429LongerThanAMinuteThrownAtOnce(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(429, ['error' => 'rate_limited', 'message' => 'x'], ['retry-after' => '3600']));
        [$e] = self::attempt(fn () => FakeTransport::client($t, [], $s)->messages->get('m'));
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertCount(1, $t->calls);
        self::assertSame([], $s->waits);
    }

    public function testDefaultSleepRandomAndClock(): void
    {
        $c = new Client('sk_test_x', ['transport' => new FakeTransport(FakeTransport::json(200, []))]);
        $start = microtime(true);
        ($c->http->sleep)(0.01);
        self::assertGreaterThanOrEqual(0.009, microtime(true) - $start);
        $r = ($c->http->random)();
        self::assertTrue($r >= 0 && $r <= 1);
        self::assertEqualsWithDelta(microtime(true), ($c->http->clock)(), 5);
    }

    public function testPagination(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['items' => [['id' => 'a'], ['id' => 'b']], 'next_cursor' => 'c1']),
            FakeTransport::json(200, ['items' => [['id' => 'c']], 'next_cursor' => 'c2']),
            FakeTransport::json(200, ['items' => [], 'next_cursor' => null]),
        );
        $page = FakeTransport::client($t)->messages->list(['limit' => 2, 'status' => 'failed', 'since' => 1790000000000]);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame([['id' => 'a'], ['id' => 'b']], $page->items);
        self::assertSame('c1', $page->nextCursor);
        self::assertTrue($page->hasNextPage());
        self::assertCount(1, $t->calls);
        $ids = [];
        foreach ($page as $m) {
            $ids[] = $m['id'];
        }
        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertSame(
            ['limit=2&status=failed&since=1790000000000', 'limit=2&status=failed&since=1790000000000&cursor=c1', 'limit=2&status=failed&since=1790000000000&cursor=c2'],
            array_map(fn ($r) => (string) parse_url($r->url, PHP_URL_QUERY), $t->calls),
        );
    }

    public function testPaginationStartCursorAndErrors(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['items' => [['id' => 'x']], 'next_cursor' => 'n']), FakeTransport::json(404, ['error' => 'not_found', 'message' => 'gone']));
        $page = FakeTransport::client($t, ['max_retries' => 0])->consumers->list(['cursor' => 'start', 'limit' => 1]);
        $got = [];
        [$e] = self::attempt(function () use ($page, &$got) {
            foreach ($page as $c) {
                $got[] = $c['id'];
            }
        });
        self::assertInstanceOf(NotFoundException::class, $e);
        self::assertSame(['x'], $got);
        self::assertSame('https://api.test/v1/consumers?limit=1&cursor=start', $t->calls[0]->url);
    }

    public function testLastPage(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['items' => [['id' => 'a']], 'next_cursor' => null]));
        $page = FakeTransport::client($t)->messages->list(['q' => '', 'event_type' => 'invoice.paid']);
        self::assertFalse($page->hasNextPage());
        self::assertNull($page->nextPage());
        self::assertSame('https://api.test/v1/messages?event_type=invoice.paid', $t->last()->url);
    }

    public function testBackoff(): void
    {
        $half = static fn (): float => 0.5;
        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], array_map(fn ($n) => HttpClient::backoff($n, $half), [0, 1, 2, 3, 4, 5, 10]));
        self::assertSame(0.375, HttpClient::backoff(0, static fn (): float => 0.0));
        self::assertSame(0.625, HttpClient::backoff(0, static fn (): float => 1.0));
    }

    public function testRateLimitWait(): void
    {
        $w = fn (array $h, float $now = 0.0) => HttpClient::rateLimitWait(new Response(429, $h), $now);
        self::assertSame(2.0, $w(['retry-after' => '2']));
        self::assertSame(0.4, $w(['retry-after' => '0.4']));
        self::assertSame(4.0, $w(['retry-after' => 'Thu, 01 Jan 1970 00:00:05 GMT'], 1));
        self::assertSame(0.0, $w(['retry-after' => 'Thu, 01 Jan 1970 00:00:05 GMT'], 9));
        self::assertSame(6.0, $w(['retry-after' => 'soon', 'x-ratelimit-reset' => '10'], 4));
        self::assertSame(0.0, $w(['retry-after' => '-3']));
        self::assertSame(0.0, $w(['x-ratelimit-reset' => '1'], 5));
        self::assertNull($w(['x-ratelimit-reset' => 'x']));
        self::assertNull($w(['x-ratelimit-reset' => '']));
        self::assertNull($w(['retry-after' => ' ']));
        self::assertNull($w([]));
        self::assertSame(60.0, HttpClient::MAX_RATE_LIMIT_WAIT);
    }

    public function testEmptyResponseIsNull(): void
    {
        $t = new FakeTransport(new Response(204));
        FakeTransport::client($t)->endpoints->delete('ep');
        self::assertSame('DELETE', $t->last()->method);
    }

    public function testQueryValues(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, []));
        $c = FakeTransport::client($t);
        self::assertSame('https://api.test/p?a=true&b=false&c=1&e=x%20y', $c->http->url('/p', ['a' => true, 'b' => false, 'c' => 1, 'd' => null, 'e' => 'x y']));
    }
}
