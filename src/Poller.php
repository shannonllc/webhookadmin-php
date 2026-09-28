<?php

declare(strict_types=1);

namespace WebhookAdmin;

use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Http\CurlTransport;
use WebhookAdmin\Http\HttpClient;
use WebhookAdmin\Http\Transport;

/**
 * Fetches messages from a polling endpoint, as the receiver, with a poller token (`sk_poll_...`, not an API key).
 *
 * Delivery is at least once: calling again with the returned `iterator` acknowledges the previous page, and calling
 * with an older iterator returns the same messages again.
 *
 * ```php
 * $poller = new Poller('ep_...', 'sk_poll_...');
 * foreach ($poller->pages(['iterator' => $saved]) as $page) {
 *     foreach ($page['data'] as $m) { handle($m['payload']); }
 *     $saved = $page['iterator']; // save it to resume from here
 * }
 * ```
 *
 * @phpstan-type PolledMessage array{id: string, event_type: string, timestamp: string, payload: array{type: string, timestamp: string, data: mixed}, headers: array<string, string>}
 * @phpstan-type PollPage array{data: list<PolledMessage>, iterator: string, done: bool}
 */
final class Poller
{
    public readonly string $endpointId;
    public readonly string $baseUrl;
    /** @internal */
    public readonly HttpClient $http;

    /**
     * @param string $endpointId The polling endpoint (`ep_...`).
     * @param string $token A poller token of that endpoint (`sk_poll_...`), from `endpoints->createPollerToken()` or the dashboard.
     * @param array{base_url?: string, timeout?: float|int, max_retries?: int, transport?: Transport} $options Same as `Client`.
     */
    public function __construct(string $endpointId, string $token, array $options = [])
    {
        if ($endpointId === '') {
            throw new WebhookAdminException('Missing endpoint ID. Pass the ID of the polling endpoint (ep_...).');
        }
        if ($token === '') {
            throw new WebhookAdminException('Missing token. Pass a poller token of the endpoint (sk_poll_...).');
        }
        $this->endpointId = $endpointId;
        $this->baseUrl = rtrim($options['base_url'] ?? Client::DEFAULT_BASE_URL, '/');
        $this->http = new HttpClient(
            $token,
            $this->baseUrl,
            (float) ($options['timeout'] ?? Client::DEFAULT_TIMEOUT),
            (int) ($options['max_retries'] ?? Client::DEFAULT_MAX_RETRIES),
            $options['transport'] ?? new CurlTransport(),
        );
    }

    /**
     * Fetches one page (`data`, `iterator`, `done`). `iterator`: the `iterator` of the previous page; omit it to start at the
     * beginning of your plan's retention. `limit`: 1 to 250, default 50. Retried on 429, 5xx and connection errors like any other read.
     *
     * @param array{iterator?: string|null, limit?: int|null} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return PollPage
     */
    public function poll(array $params = [], array $options = []): array
    {
        return $this->http->request(
            'GET',
            '/v1/poller/' . rawurlencode($this->endpointId),
            true,
            query: ['iterator' => $params['iterator'] ?? null, 'limit' => $params['limit'] ?? null],
            options: array_intersect_key($options, ['timeout' => true, 'max_retries' => true]),
        );
    }

    /**
     * Fetches pages from `iterator` until a page with `done` true, which is yielded too.
     * Each call acknowledges the page before it, so save `$page['iterator']` after processing a page to resume later.
     * The last page is acknowledged by the next call (the next time you poll with the saved iterator).
     *
     * @param array{iterator?: string|null, limit?: int|null} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return \Generator<int, PollPage>
     */
    public function pages(array $params = [], array $options = []): \Generator
    {
        $iterator = $params['iterator'] ?? null;
        while (true) {
            $page = $this->poll(['iterator' => $iterator, 'limit' => $params['limit'] ?? null], $options);
            yield $page;
            if ($page['done']) {
                return;
            }
            $iterator = $page['iterator'];
        }
    }

    /**
     * Every message of `pages()`, one by one. Use `pages()` when you need the iterator to resume later.
     *
     * @param array{iterator?: string|null, limit?: int|null} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return \Generator<int, PolledMessage>
     */
    public function messages(array $params = [], array $options = []): \Generator
    {
        foreach ($this->pages($params, $options) as $page) {
            foreach ($page['data'] as $m) {
                yield $m;
            }
        }
    }
}
