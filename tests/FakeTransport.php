<?php

declare(strict_types=1);

namespace WebhookAdmin\Tests;

use WebhookAdmin\Client;
use WebhookAdmin\Http\Request;
use WebhookAdmin\Http\Response;
use WebhookAdmin\Http\Transport;

/** Records calls and returns prepared replies in order; the last one keeps being returned. */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $calls = [];
    /** @var list<Response|\Throwable|\Closure> */
    private array $replies;

    public function __construct(Response|\Throwable|\Closure ...$replies)
    {
        $this->replies = array_values($replies);
    }

    public function send(Request $request): Response
    {
        $this->calls[] = $request;
        $r = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
        if ($r instanceof \Throwable) {
            throw $r;
        }
        if ($r instanceof \Closure) {
            return $r($request);
        }
        return $r;
    }

    public function last(): Request
    {
        return $this->calls[count($this->calls) - 1];
    }

    /** @return array{0: string, 1: string, 2: mixed} method, path with query, decoded body */
    public function lastCall(): array
    {
        $r = $this->last();
        $u = parse_url($r->url);
        $path = ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : '');
        return [$r->method, $path, $r->body === null ? null : json_decode($r->body, true)];
    }

    public static function json(int $status, mixed $body = null, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json', ...$headers], $body === null ? '' : json_encode($body));
    }

    /** @param array<string, mixed> $options */
    public static function client(self $t, array $options = [], ?Sleeps $sleeps = null): Client
    {
        $c = new Client('sk_test_abc', ['base_url' => 'https://api.test', 'transport' => $t, ...$options]);
        $sleeps ??= new Sleeps();
        $c->http->sleep = \Closure::fromCallable($sleeps);
        $c->http->random = static fn (): float => 0.5;
        return $c;
    }
}
