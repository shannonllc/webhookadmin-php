<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use PHPUnit\Framework\TestCase;
use WebhookAdmin\Exception\ApiException;
use WebhookAdmin\Exception\ConflictException;
use WebhookAdmin\Exception\NotFoundException;

/** Each method calls the documented method, path and body (docs/api.md, section 2). */
final class ResourcesTest extends TestCase
{
    public function testMessages(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['ok' => true]));
        $c = FakeTransport::client($t);
        $c->messages->send(['consumer' => 'cus_1', 'event_type' => 'invoice.paid', 'payload' => ['a' => [1, '二'], 'url' => 'https://x/y', 'f' => 1.0]], ['idempotency_key' => 'k1']);
        self::assertSame(['POST', '/v1/messages', ['consumer' => 'cus_1', 'event_type' => 'invoice.paid', 'payload' => ['a' => [1, '二'], 'url' => 'https://x/y', 'f' => 1.0]]], $t->lastCall());
        self::assertSame('{"consumer":"cus_1","event_type":"invoice.paid","payload":{"a":[1,"二"],"url":"https://x/y","f":1.0}}', $t->last()->body);
        self::assertSame('application/json', $t->last()->headers['content-type']);
        $c->messages->send(['consumer' => 'c', 'event_type' => 'e', 'payload' => new \stdClass()]);
        self::assertStringEndsWith('"payload":{}}', (string) $t->last()->body);
        $c->messages->send(['consumer' => 'c', 'event_type' => 'e', 'payload' => 1, 'transformations_params' => ['ch' => '#a']]);
        self::assertSame(['consumer' => 'c', 'event_type' => 'e', 'payload' => 1, 'transformations_params' => ['ch' => '#a']], $t->lastCall()[2]);
        $c->messages->send(['consumer' => 'c', 'event_type' => 'e', 'payload' => 1, 'transformations_params' => []]);
        self::assertStringEndsWith('"transformations_params":{}}', (string) $t->last()->body);
        $c->messages->get('msg_1/../x');
        self::assertSame('https://api.test/v1/messages/msg_1%2F..%2Fx', $t->last()->url);
    }

    public function testDeliveriesAndConsumers(): void
    {
        $t = new FakeTransport(FakeTransport::json(202, ['id' => 'x']));
        $c = FakeTransport::client($t);
        $c->deliveries->retry('dlv_1');
        self::assertSame(['POST', '/v1/deliveries/dlv_1/retry', null], $t->lastCall());
        $c->consumers->create(['external_id' => 'cus_1']);
        self::assertSame(['POST', '/v1/consumers', ['external_id' => 'cus_1']], $t->lastCall());
        $c->consumers->create(['external_id' => 'cus_2', 'name' => 'Acme', 'unknown' => 1]);
        self::assertSame(['POST', '/v1/consumers', ['external_id' => 'cus_2', 'name' => 'Acme']], $t->lastCall());
    }

    public function testEndpoints(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['ok' => true]));
        $c = FakeTransport::client($t);
        $c->endpoints->create(['consumer_id' => 'con_1', 'url' => 'https://x.example/wh']);
        self::assertSame(['POST', '/v1/endpoints', ['consumer_id' => 'con_1', 'url' => 'https://x.example/wh']], $t->lastCall());
        $full = [
            'consumer_id' => 'con_1',
            'url' => 'https://x.example/wh',
            'event_types' => null,
            'fixed_ip' => true,
            'description' => 'd',
            'retry' => ['count' => 3, 'interval' => '5m'],
            'compat_signature' => ['header' => 'x-hub-signature-256', 'content' => 'body', 'encoding' => 'hex', 'prefix' => 'sha256='],
        ];
        $c->endpoints->create($full);
        self::assertSame($full, $t->lastCall()[2]);
        $c->endpoints->get('ep_1');
        self::assertSame(['GET', '/v1/endpoints/ep_1', null], $t->lastCall());
        $c->endpoints->update('ep_1', ['status' => 'paused']);
        self::assertSame(['PATCH', '/v1/endpoints/ep_1', ['status' => 'paused']], $t->lastCall());
        $c->endpoints->update('ep_1', ['event_types' => null, 'retry' => null, 'compat_signature' => null, 'url' => 'https://y', 'description' => '']);
        self::assertSame('{"event_types":null,"retry":null,"compat_signature":null,"url":"https://y","description":""}', $t->last()->body);
        $c->endpoints->update('ep_1', []);
        self::assertSame('{}', $t->last()->body);
        $c->endpoints->create(['consumer_id' => 'con_1', 'url' => 'https://x', 'body_format' => 'raw', 'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']);
        self::assertSame(['consumer_id' => 'con_1', 'url' => 'https://x', 'body_format' => 'raw', 'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'], $t->lastCall()[2]);
        $c->endpoints->update('ep_1', ['body_format' => 'standard', 'secret' => 'whsec_x']);
        self::assertSame('{"body_format":"standard"}', $t->last()->body);
        $c->endpoints->rotateSecret('ep_1');
        self::assertSame(['POST', '/v1/endpoints/ep_1/rotate-secret', null], $t->lastCall());
        $c->endpoints->recover('ep_1', ['since' => 1790000000000]);
        self::assertSame(['POST', '/v1/endpoints/ep_1/recover', ['since' => 1790000000000]], $t->lastCall());
        $c->endpoints->recovery('ep_1', 'rcv_1');
        self::assertSame(['GET', '/v1/endpoints/ep_1/recoveries/rcv_1', null], $t->lastCall());
        $c->endpoints->sendTest('ep_1');
        self::assertSame('{}', $t->last()->body);
        self::assertSame('POST', $t->last()->method);
        $c->endpoints->sendTest('ep_1', ['event_type' => 'user.created']);
        self::assertSame(['POST', '/v1/endpoints/ep_1/test', ['event_type' => 'user.created']], $t->lastCall());
        $c->endpoints->delete('ep_1');
        self::assertSame(['DELETE', '/v1/endpoints/ep_1', null], $t->lastCall());
    }

    public function testTransformation(): void
    {
        $tf = ['endpoint_id' => 'ep_1', 'code' => 'function handler(w) { return w }', 'updated_at' => 1000];
        $result = ['result' => 'cancel', 'logs' => []];
        $t = new FakeTransport(FakeTransport::json(200, $tf), FakeTransport::json(200, $tf), FakeTransport::json(204), FakeTransport::json(200, $result));
        $c = FakeTransport::client($t);
        self::assertSame($tf, $c->endpoints->getTransformation('ep_1'));
        self::assertSame(['GET', '/v1/endpoints/ep_1/transformation', null], $t->lastCall());
        self::assertSame($tf, $c->endpoints->setTransformation('ep_1', ['code' => $tf['code'], 'unknown' => 1]));
        self::assertSame(['PUT', '/v1/endpoints/ep_1/transformation', ['code' => $tf['code']]], $t->lastCall());
        self::assertSame('application/json', $t->last()->headers['content-type']);
        $c->endpoints->deleteTransformation('ep_1');
        self::assertSame(['DELETE', '/v1/endpoints/ep_1/transformation', null], $t->lastCall());
        self::assertSame($result, $c->endpoints->testTransformation('ep_1', ['payload' => ['n' => 1], 'event_type' => 'a.b']));
        self::assertSame(['POST', '/v1/endpoints/ep_1/transformation/test', ['payload' => ['n' => 1], 'event_type' => 'a.b']], $t->lastCall());
        $c->endpoints->testTransformation('ep_1', ['code' => 'x', 'payload' => null]);
        self::assertSame('{"code":"x","payload":null}', $t->last()->body);
        $c->endpoints->testTransformation('ep_1');
        self::assertSame('{}', $t->last()->body);
        $c->endpoints->testTransformation('ep_1', ['variables' => ['A' => '1'], 'transformations_params' => []]);
        self::assertSame('{"variables":{"A":"1"},"transformations_params":{}}', $t->last()->body);
        $c->endpoints->testTransformation('ep_1', ['variables' => null]);
        self::assertSame('{"variables":null}', $t->last()->body);
        $c->endpoints->setTransformation('ep_1', ['enabled' => false, 'variables' => ['TOKEN' => 't']]);
        self::assertSame(['PUT', '/v1/endpoints/ep_1/transformation', ['enabled' => false, 'variables' => ['TOKEN' => 't']]], $t->lastCall());
        $c->endpoints->setTransformation('ep_1', ['variables' => []]);
        self::assertSame('{"variables":{}}', $t->last()->body);
        $c->endpoints->setTransformation('ep_1', ['variables' => null]);
        self::assertSame('{"variables":null}', $t->last()->body);
        $c->endpoints->setTransformation('ep_1', []);
        self::assertSame('{}', $t->last()->body);
    }

    public function testDestinations(): void
    {
        $ep = ['id' => 'ep_1', 'type' => 'sqs', 'destination' => ['region' => 'ap-northeast-1', 'credentials_hint' => 'AKIA…MPLE']];
        $result = ['ok' => true, 'via' => 'sqs', 'response_status' => 200, 'duration_ms' => 3, 'error' => null, 'response_head' => null, 'ref' => 'm'];
        $t = new FakeTransport(FakeTransport::json(201, $ep), FakeTransport::json(200, $ep), FakeTransport::json(200, $result), FakeTransport::json(200, $result));
        $c = FakeTransport::client($t);
        $dest = ['region' => 'ap-northeast-1', 'account_id' => '123456789012', 'queue_name' => 'q', 'credentials' => ['access_key_id' => 'A', 'secret_access_key' => 'S']];
        self::assertSame($ep, $c->endpoints->create(['consumer_id' => 'con_1', 'type' => 'sqs', 'destination' => $dest]));
        self::assertSame(['POST', '/v1/endpoints', ['consumer_id' => 'con_1', 'type' => 'sqs', 'destination' => $dest]], $t->lastCall());
        $c->endpoints->update('ep_1', ['destination' => ['queue_name' => 'other']]);
        self::assertSame(['PATCH', '/v1/endpoints/ep_1', ['destination' => ['queue_name' => 'other']]], $t->lastCall());
        self::assertSame($result, $c->endpoints->testDestination(['endpoint_id' => 'ep_1', 'unknown' => 1]));
        self::assertSame(['POST', '/v1/destinations/test', ['endpoint_id' => 'ep_1']], $t->lastCall());
        $c->endpoints->testDestination(['type' => 'sqs', 'destination' => ['region' => 'x'], 'fixed_ip' => true]);
        self::assertSame('{"type":"sqs","destination":{"region":"x"},"fixed_ip":true}', $t->last()->body);
    }

    public function testDestinationTestNotRetriedOn5xx(): void
    {
        $t = new FakeTransport(FakeTransport::json(503, ['error' => 'unavailable', 'message' => 'x']));
        $c = FakeTransport::client($t, ['max_retries' => 2]);
        try {
            $c->endpoints->testDestination(['endpoint_id' => 'ep_1']);
            self::fail('not thrown');
        } catch (\WebhookAdmin\Exception\ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(1, $t->calls);
    }

    public function testTransformationRetriedOn5xx(): void
    {
        $tf = ['endpoint_id' => 'ep_1', 'code' => 'x', 'updated_at' => 1];
        $unavailable = FakeTransport::json(503, ['error' => 'unavailable', 'message' => 'x']);
        $t = new FakeTransport($unavailable, FakeTransport::json(200, $tf), $unavailable, FakeTransport::json(200, ['result' => 'cancel', 'logs' => []]), $unavailable, FakeTransport::json(204));
        $c = FakeTransport::client($t, ['max_retries' => 1]);
        self::assertSame($tf, $c->endpoints->setTransformation('ep_1', ['code' => 'x']));
        self::assertSame('cancel', $c->endpoints->testTransformation('ep_1')['result']);
        $c->endpoints->deleteTransformation('ep_1');
        self::assertCount(6, $t->calls);
    }

    public function testTransformationNotFound(): void
    {
        $t = new FakeTransport(FakeTransport::json(404, ['error' => 'not_found', 'message' => 'no transformation']));
        $this->expectException(NotFoundException::class);
        FakeTransport::client($t)->endpoints->getTransformation('ep_1');
    }

    public function testPollingEndpointAndPollerTokens(): void
    {
        $token = ['id' => 'ptk_1', 'endpoint_id' => 'ep_1', 'name' => 'erp', 'prefix' => 'sk_poll_abcd...', 'created_at' => 1, 'last_used_at' => null];
        $t = new FakeTransport(
            FakeTransport::json(201, ['id' => 'ep_1', 'type' => 'polling', 'url' => null, 'poller_url' => 'https://api.test/v1/poller/ep_1']),
            FakeTransport::json(200, ['items' => [$token]]),
            FakeTransport::json(201, [...$token, 'token' => 'sk_poll_x']),
            FakeTransport::json(201, [...$token, 'token' => 'sk_poll_y']),
            FakeTransport::json(204),
        );
        $c = FakeTransport::client($t);
        self::assertSame('https://api.test/v1/poller/ep_1', $c->endpoints->create(['consumer_id' => 'con_1', 'type' => 'polling'])['poller_url']);
        self::assertSame(['POST', '/v1/endpoints', ['consumer_id' => 'con_1', 'type' => 'polling']], $t->lastCall());
        self::assertSame(['items' => [$token]], $c->endpoints->listPollerTokens('ep_1'));
        self::assertSame(['GET', '/v1/endpoints/ep_1/poller-tokens', null], $t->lastCall());
        self::assertSame('sk_poll_x', $c->endpoints->createPollerToken('ep_1', ['name' => 'erp'])['token']);
        self::assertSame(['POST', '/v1/endpoints/ep_1/poller-tokens', ['name' => 'erp']], $t->lastCall());
        self::assertSame('sk_poll_y', $c->endpoints->createPollerToken('ep_1')['token']);
        self::assertSame('{}', $t->last()->body);
        $c->endpoints->deletePollerToken('ep_1', 'ptk/1');
        self::assertSame('DELETE', $t->last()->method);
        self::assertSame('https://api.test/v1/endpoints/ep_1/poller-tokens/ptk%2F1', $t->last()->url);
    }

    public function testPollerTokensRetries(): void
    {
        $unavailable = FakeTransport::json(503, ['error' => 'unavailable', 'message' => 'x']);
        $t = new FakeTransport($unavailable, FakeTransport::json(200, ['items' => []]), $unavailable, FakeTransport::json(204), $unavailable);
        $c = FakeTransport::client($t, ['max_retries' => 1]);
        $c->endpoints->listPollerTokens('ep_1');
        $c->endpoints->deletePollerToken('ep_1', 'ptk_1');
        self::assertCount(4, $t->calls);
        try {
            $c->endpoints->createPollerToken('ep_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(5, $t->calls);
    }

    public function testEndpointsList(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['items' => [['id' => 'ep_1']]]));
        $c = FakeTransport::client($t);
        $page = $c->endpoints->list(['consumer_id' => 'con_1']);
        self::assertSame([['id' => 'ep_1']], $page->items);
        self::assertNull($page->nextCursor);
        self::assertSame('https://api.test/v1/endpoints?consumer_id=con_1', $t->last()->url);
        $c->endpoints->list();
        self::assertSame('https://api.test/v1/endpoints', $t->last()->url);
    }

    public function testPortal(): void
    {
        $t = new FakeTransport(FakeTransport::json(503, []), FakeTransport::json(201, ['url' => 'u', 'expires_at' => 1]));
        $c = FakeTransport::client($t);
        self::assertSame(['url' => 'u', 'expires_at' => 1], $c->portal->createLink('con_1'));
        self::assertCount(2, $t->calls); // retried: safe to repeat
        self::assertSame('{}', $t->last()->body);
        self::assertSame('https://api.test/v1/consumers/con_1/portal', $t->last()->url);
        $c->portal->createLink('con_1', ['frame_origin' => 'https://app.example.com', 'locale' => 'en']);
        self::assertSame(['frame_origin' => 'https://app.example.com', 'locale' => 'en'], $t->lastCall()[2]);
    }

    private static function et(string $name, string $description = ''): array
    {
        return ['name' => $name, 'description' => $description, 'archived_at' => null];
    }

    public function testEventTypesListAndCreate(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['items' => [self::et('a')]]), FakeTransport::json(201, self::et('b', 'B')));
        $c = FakeTransport::client($t);
        self::assertSame(['items' => [self::et('a')]], $c->eventTypes->list());
        self::assertSame(['GET', '/v1/event-types', null], $t->lastCall());
        self::assertSame(self::et('b', 'B'), $c->eventTypes->create(['name' => 'b', 'description' => 'B']));
        self::assertSame(['POST', '/v1/event-types', ['name' => 'b', 'description' => 'B']], $t->lastCall());
    }

    public function testEnsure(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['items' => [self::et('a', 'kept')]]),
            FakeTransport::json(201, self::et('b', 'B')),
            FakeTransport::json(201, self::et('c')),
        );
        $r = FakeTransport::client($t)->eventTypes->ensure([['name' => 'a', 'description' => 'new'], ['name' => 'b', 'description' => 'B'], 'c', 'b']);
        self::assertSame(['created' => [self::et('b', 'B'), self::et('c')], 'existing' => [self::et('a', 'kept')]], $r);
        self::assertSame([null, '{"name":"b","description":"B"}', '{"name":"c"}'], array_map(fn ($r) => $r->body, $t->calls));
    }

    public function testEnsureRace(): void
    {
        $t = new FakeTransport(
            FakeTransport::json(200, ['items' => []]),
            FakeTransport::json(409, ['error' => 'conflict', 'message' => 'exists']),
            FakeTransport::json(200, ['items' => [self::et('a', 'theirs'), self::et('z')]]),
        );
        self::assertSame(['created' => [], 'existing' => [self::et('a', 'theirs')]], FakeTransport::client($t)->eventTypes->ensure(['a']));
    }

    public function testEnsureOtherErrors(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['items' => []]), FakeTransport::json(404, ['error' => 'not_found', 'message' => 'x']));
        $this->expectException(NotFoundException::class);
        FakeTransport::client($t)->eventTypes->ensure(['a']);
    }

    public function testConflict(): void
    {
        $t = new FakeTransport(FakeTransport::json(409, ['error' => 'conflict', 'message' => 'dup']));
        $this->expectException(ConflictException::class);
        FakeTransport::client($t)->consumers->create(['external_id' => 'x']);
    }
}
