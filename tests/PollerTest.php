<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use PHPUnit\Framework\TestCase;
use WebhookAdmin\Exception\AuthenticationException;
use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Http\Response;
use WebhookAdmin\Poller;
use WebhookAdmin\Version;

/** The receiver of a polling endpoint calls GET /v1/poller/{id} with its token and follows the iterator. */
final class PollerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function msg(int $n): array
    {
        return [
            'id' => "msg_$n",
            'event_type' => 'invoice.paid',
            'timestamp' => '2026-09-29T00:00:00.000Z',
            'payload' => ['type' => 'invoice.paid', 'timestamp' => '2026-09-29T00:00:00.000Z', 'data' => ['n' => $n]],
            'headers' => ['webhook-id' => "msg_$n", 'webhook-timestamp' => '1790000000', 'webhook-signature' => 'v1,x'],
        ];
    }

    /** @param list<array<string, mixed>> $data */
    private static function page(string $iterator, bool $done, array $data = []): Response
    {
        return FakeTransport::json(200, ['data' => $data, 'iterator' => $iterator, 'done' => $done]);
    }

    /** @param array<string, mixed> $options */
    private static function poller(FakeTransport $t, array $options = [], ?Sleeps $sleeps = null): Poller
    {
        $p = new Poller('ep_1', 'sk_poll_abc', ['base_url' => 'https://api.test/', 'transport' => $t, ...$options]);
        $p->http->sleep = \Closure::fromCallable($sleeps ?? new Sleeps());
        $p->http->random = static fn (): float => 0.5;
        return $p;
    }

    public function testPollSendsTokenIteratorAndLimit(): void
    {
        $t = new FakeTransport(self::page('it_1', false, [self::msg(1)]));
        $p = self::poller($t);
        self::assertSame('ep_1', $p->endpointId);
        self::assertSame('https://api.test', $p->baseUrl);
        $page = $p->poll(['iterator' => 'it_0', 'limit' => 10]);
        self::assertSame('it_1', $page['iterator']);
        self::assertSame('msg_1', $page['data'][0]['id']);
        self::assertSame(['GET', '/v1/poller/ep_1?iterator=it_0&limit=10', null], $t->lastCall());
        self::assertSame('Bearer sk_poll_abc', $t->last()->headers['authorization']);
        self::assertSame(Version::USER_AGENT, $t->last()->headers['user-agent']);
        $p->poll();
        self::assertSame('https://api.test/v1/poller/ep_1', $t->last()->url);
    }

    public function testPagesFollowTheIteratorUntilDone(): void
    {
        $t = new FakeTransport(self::page('it_1', false, [self::msg(1), self::msg(2)]), self::page('it_2', true, [self::msg(3)]));
        $seen = [];
        foreach (self::poller($t)->pages(['iterator' => 'it_0', 'limit' => 2]) as $page) {
            $seen[] = $page['iterator'];
        }
        self::assertSame(['it_1', 'it_2'], $seen);
        self::assertSame(['https://api.test/v1/poller/ep_1?iterator=it_0&limit=2', 'https://api.test/v1/poller/ep_1?iterator=it_1&limit=2'], array_map(static fn ($r) => $r->url, $t->calls));
    }

    public function testEventTypesAndAfter(): void
    {
        $t = new FakeTransport(self::page('i', true), self::page('i', true), self::page('i', true));
        $p = self::poller($t);
        $p->poll(['event_types' => ['invoice.paid', 'invoice.failed'], 'after' => new \DateTimeImmutable('2026-09-29T09:00:00+09:00')]);
        self::assertSame('https://api.test/v1/poller/ep_1?event_type=invoice.paid&event_type=invoice.failed&after=2026-09-29T09%3A00%3A00.000%2B09%3A00', $t->last()->url);
        $p->poll(['event_types' => [], 'after' => '2026-09-29T00:00:00Z']);
        self::assertSame('https://api.test/v1/poller/ep_1?after=2026-09-29T00%3A00%3A00Z', $t->last()->url);
        $p->poll(['event_types' => ['a b'], 'iterator' => 'x/y']);
        self::assertSame('https://api.test/v1/poller/ep_1?iterator=x%2Fy&event_type=a%20b', $t->last()->url);
    }

    public function testPagesKeepTheFilters(): void
    {
        $t = new FakeTransport(self::page('it_1', false, [self::msg(1)]), self::page('it_1', true));
        foreach (self::poller($t)->pages(['event_types' => ['a.b'], 'limit' => 5]) as $_) {
        }
        self::assertSame(
            ['https://api.test/v1/poller/ep_1?limit=5&event_type=a.b', 'https://api.test/v1/poller/ep_1?iterator=it_1&limit=5&event_type=a.b'],
            array_map(static fn ($r) => $r->url, $t->calls),
        );
    }

    public function testMessagesFlattenThePages(): void
    {
        $t = new FakeTransport(self::page('it_1', false, [self::msg(1)]), self::page('it_1', false), self::page('it_2', true, [self::msg(2)]));
        $ids = [];
        foreach (self::poller($t)->messages() as $m) {
            $ids[] = $m['id'];
        }
        self::assertSame(['msg_1', 'msg_2'], $ids);
        self::assertSame('https://api.test/v1/poller/ep_1', $t->calls[0]->url);
    }

    public function testRetriesAndErrors(): void
    {
        $sleeps = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(503, []), self::page('i', true));
        self::assertTrue(self::poller($t, ['max_retries' => 1], $sleeps)->poll([], ['max_retries' => 1])['done']);
        self::assertCount(2, $t->calls);
        self::assertSame([0.5], $sleeps->waits);
        $bad = new FakeTransport(FakeTransport::json(401, ['error' => 'unauthorized', 'message' => 'bad token']));
        $this->expectException(AuthenticationException::class);
        self::poller($bad)->messages()->current();
    }

    public function testRequiresEndpointId(): void
    {
        $this->expectException(WebhookAdminException::class);
        $this->expectExceptionMessage('endpoint ID');
        new Poller('', 'sk_poll_abc');
    }

    public function testRequiresToken(): void
    {
        $this->expectException(WebhookAdminException::class);
        $this->expectExceptionMessage('token');
        new Poller('ep_1', '');
    }

    public function testDefaults(): void
    {
        $p = new Poller('ep_1', 'sk_poll_abc');
        self::assertSame('https://api.webhookadmin.com', $p->baseUrl);
        self::assertSame('https://api.webhookadmin.com', $p->http->baseUrl);
    }
}
