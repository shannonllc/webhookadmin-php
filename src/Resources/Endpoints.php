<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Page;

final class Endpoints extends Resource
{
    private const CREATE = ['consumer_id', 'url', 'event_types', 'fixed_ip', 'description', 'retry', 'compat_signature'];
    private const UPDATE = ['url', 'event_types', 'status', 'description', 'retry', 'compat_signature'];

    private static function path(string $endpointId, string $rest = ''): string
    {
        return '/v1/endpoints/' . self::id($endpointId) . $rest;
    }

    /**
     * Creates an endpoint. The signing secret is returned only here. Requires `endpoints:write`.
     *
     * @param array{consumer_id: string, url: string, event_types?: list<string>|null, fixed_ip?: bool, description?: string,
     *        retry?: array{count: int, interval: string}|null, compat_signature?: array{header: string, content: string, encoding: string, prefix?: string}|null} $params
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
     * `'compat_signature' => null` removes it. Requires `endpoints:write`.
     *
     * @param array{url?: string, event_types?: list<string>|null, status?: string, description?: string,
     *        retry?: array{count: int, interval: string}|null, compat_signature?: array{header: string, content: string, encoding: string, prefix?: string}|null} $params
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
}
