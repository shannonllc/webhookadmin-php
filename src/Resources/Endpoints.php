<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Page;

final class Endpoints extends Resource
{
    private const CREATE = ['consumer_id', 'url', 'event_types', 'fixed_ip', 'description', 'retry', 'compat_signature', 'type', 'destination'];
    private const UPDATE = ['url', 'event_types', 'status', 'description', 'retry', 'compat_signature', 'destination'];

    private static function path(string $endpointId, string $rest = ''): string
    {
        return '/v1/endpoints/' . self::id($endpointId) . $rest;
    }

    /**
     * Creates an endpoint. The signing secret is returned only here. Requires `endpoints:write`.
     * `url` is required for webhooks (`type` `http`, the default). Other types (`sqs`, `eventbridge`, `pubsub`, `s3`, `r2`, `gcs`,
     * `azure_blob`, `servicebus`, `kafka`, `rabbitmq`; Pro plan and above) take `destination` instead, with `credentials`
     * (write-only). The type cannot be changed later.
     *
     * @param array{consumer_id: string, url?: string, event_types?: list<string>|null, fixed_ip?: bool, description?: string,
     *        retry?: array{count: int, interval: string}|null, compat_signature?: array{header: string, content: string, encoding: string, prefix?: string}|null,
     *        type?: string, destination?: array<string, mixed>} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function create(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/endpoints', false, body: self::pick($params, self::CREATE), hasBody: true, options: self::opts($options));
    }

    /**
     * Lists endpoints, optionally for one consumer. All endpoints come in one page. Requires `logs:read`.
     *
     * @param array{consumer_id?: string} $params
     * @return Page<array<string, mixed>>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        $fetch = function (?string $cursor) use ($params, $options): array {
            $r = $this->http->request('GET', '/v1/endpoints', true, query: ['consumer_id' => $params['consumer_id'] ?? null, 'cursor' => $cursor], options: self::opts($options));
            return ['items' => $r['items'] ?? [], 'next_cursor' => $r['next_cursor'] ?? null];
        };
        return new Page($fetch(null), $fetch);
    }

    /**
     * Gets an endpoint with its latest attempts. Requires `logs:read`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function get(string $endpointId, array $options = []): array
    {
        return $this->http->request('GET', self::path($endpointId), true, options: self::opts($options));
    }

    /**
     * Updates an endpoint. Only the keys you pass are sent. `'status' => 'active'` resumes a paused or disabled endpoint;
     * `'event_types' => null` receives all event types, `'retry' => null` goes back to the default policy and
     * `'compat_signature' => null` removes it. `destination` changes the settings of a destination other than a webhook;
     * omit its `credentials` to keep the current ones. Requires `endpoints:write`.
     *
     * @param array{url?: string, event_types?: list<string>|null, status?: string, description?: string,
     *        retry?: array{count: int, interval: string}|null, compat_signature?: array{header: string, content: string, encoding: string, prefix?: string}|null,
     *        destination?: array<string, mixed>} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function update(string $endpointId, array $params, array $options = []): array
    {
        return $this->http->request('PATCH', self::path($endpointId), true, body: self::object(self::pick($params, self::UPDATE)), hasBody: true, options: self::opts($options));
    }

    /**
     * Deletes an endpoint. Requires `endpoints:write`.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function delete(string $endpointId, array $options = []): void
    {
        $this->http->request('DELETE', self::path($endpointId), true, options: self::opts($options));
    }

    /**
     * Issues a new signing secret. The previous one keeps signing for 24 hours. Requires `endpoints:write`.
     *
     * @return array{secret: string}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function rotateSecret(string $endpointId, array $options = []): array
    {
        return $this->http->request('POST', self::path($endpointId, '/rotate-secret'), false, options: self::opts($options));
    }

    /**
     * Resends every delivery to this endpoint that ended `failed`, for messages created at or after `since`
     * (Unix milliseconds), as the same messages (same `webhook-id`). Runs in the background; check it with
     * `recovery()`. The endpoint must be `active`. Requires `messages:retry`.
     *
     * @param array{since: int} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function recover(string $endpointId, array $params, array $options = []): array
    {
        return $this->http->request('POST', self::path($endpointId, '/recover'), false, body: self::pick($params, ['since']), hasBody: true, options: self::opts($options));
    }

    /**
     * Gets the progress of a bulk resend. Requires `logs:read`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function recovery(string $endpointId, string $recoveryId, array $options = []): array
    {
        return $this->http->request('GET', self::path($endpointId, '/recoveries/' . self::id($recoveryId)), true, options: self::opts($options));
    }

    /**
     * Sends a test message to this endpoint only (default event type `webhook.test`). Not counted in usage.
     * Requires `messages:send`.
     *
     * @param array{event_type?: string} $params
     * @return array{message_id: string}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function sendTest(string $endpointId, array $params = [], array $options = []): array
    {
        return $this->http->request('POST', self::path($endpointId, '/test'), false, body: self::object(self::pick($params, ['event_type'])), hasBody: true, options: self::opts($options));
    }

    /**
     * Sends one test message to a destination other than a webhook right away and returns the result
     * (`ok`, `via`, `response_status`, `duration_ms`, `error`, `response_head`, `ref`). Nothing is recorded.
     * Pass `endpoint_id` to use the saved settings and credentials, or `type` and `destination` to try settings before saving.
     * Counts toward the daily limit of test sends. Not retried. Pro plan and above. Requires `endpoints:write`.
     *
     * @param array{endpoint_id?: string, type?: string, destination?: array<string, mixed>, fixed_ip?: bool} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function testDestination(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/destinations/test', false, body: self::object(self::pick($params, ['endpoint_id', 'type', 'destination', 'fixed_ip'])), hasBody: true, options: self::opts($options));
    }

    /**
     * Gets the endpoint's transformation (`endpoint_id`, `code`, `updated_at`). Throws `NotFoundException` when there is none.
     * Requires `logs:read`.
     *
     * @return array{endpoint_id: string, code: string, updated_at: int}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function getTransformation(string $endpointId, array $options = []): array
    {
        return $this->http->request('GET', self::path($endpointId, '/transformation'), true, options: self::opts($options));
    }

    /**
     * Saves the endpoint's transformation, which runs right before every attempt. The signature covers the transformed body.
     * `code` is JavaScript defining `function handler(webhook)`, up to 16 KB. `webhook` is `{ method, url, eventType, payload, headers, cancel }`;
     * return it after changing `payload`, `headers`, `method` (`POST` / `PUT` / `PATCH`) or the path and query of `url`, or set `cancel: true`.
     * Pro plan and above. Requires `endpoints:write`.
     *
     * @param array{code: string} $params
     * @return array{endpoint_id: string, code: string, updated_at: int}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function setTransformation(string $endpointId, array $params, array $options = []): array
    {
        return $this->http->request('PUT', self::path($endpointId, '/transformation'), true, body: self::pick($params, ['code']), hasBody: true, options: self::opts($options));
    }

    /**
     * Removes the endpoint's transformation. Requires `endpoints:write`.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function deleteTransformation(string $endpointId, array $options = []): void
    {
        $this->http->request('DELETE', self::path($endpointId, '/transformation'), true, options: self::opts($options));
    }

    /**
     * Runs a transformation against a sample payload and returns the result. Nothing is sent or saved.
     * `code` defaults to the saved transformation, `payload` (sample `data`) to `{ test: true, endpoint_id }` and
     * `event_type` to `webhook.test`. `result` is `send` (with `method`, `url`, `headers`, `payload`, `changed`),
     * `cancel`, or `error` (with `error` => `['kind', 'message']`); `logs` holds `console.log` output.
     * Requires `endpoints:write`.
     *
     * @param array{code?: string, payload?: mixed, event_type?: string} $params
     * @return array{result: 'send'|'cancel'|'error', logs: list<string>, method?: string, url?: string, headers?: array<string, string>,
     *         payload?: mixed, changed?: bool, error?: array{kind: string, message: string}}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function testTransformation(string $endpointId, array $params = [], array $options = []): array
    {
        return $this->http->request('POST', self::path($endpointId, '/transformation/test'), true, body: self::object(self::pick($params, ['code', 'payload', 'event_type'])), hasBody: true, options: self::opts($options));
    }
}
