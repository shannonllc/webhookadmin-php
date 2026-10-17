<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Page;

/**
 * Sources: URLs that receive webhooks from other services (Stripe, GitHub and so on), verify their signatures and turn them
 * into messages of a consumer, delivered to that consumer's endpoints like messages sent with `messages->send()`.
 *
 * A source is `['id' => 'src_...', 'name', 'provider', 'consumer_id', 'consumer' => ['id', 'external_id', 'name'],
 * 'status' => 'active'|'paused', 'url', 'secret_hint' => string|null, 'config', 'held', 'created_at', 'updated_at']`.
 * `url` is what you register with the other service (it accepts `POST`, `PUT`, `PATCH` and `DELETE`, with a body of up to 10 MB).
 * A paused source still accepts webhooks and holds them (`held` is the number waiting); they become messages when the source is
 * set back to `active`.
 */
final class Sources extends Resource
{
    private const CREATE = ['name', 'provider', 'consumer_id', 'secret', 'config', 'status'];
    private const UPDATE = ['name', 'provider', 'consumer_id', 'status', 'config'];

    private static function path(string $sourceId, string $rest = ''): string
    {
        return '/v1/sources/' . self::id($sourceId) . $rest;
    }

    /**
     * Creates a source. Throws `PlanLimitException` when the plan's number of sources is reached. Requires `endpoints:write`.
     * `name`: up to 100 characters. `provider`: `stripe`, `github`, `shopify`, `slack`, `meta`, `zoom`, `standard`
     * (Standard Webhooks), `hmac` (an HMAC-SHA256 of the body in the header `config.header`), `basic` (Basic authentication,
     * `config.username` and the secret as the password), `api_key` (the secret in the header `config.header` or the query
     * parameter `config.query`) or `none` (no verification).
     * `consumer_id`: the consumer whose messages the received webhooks become. `secret` verifies the signatures (`whsec_...`
     * for `standard`, otherwise printable ASCII without spaces); it can also be set later with `setSecret()`.
     * `config`: `event_type` (used when the event type cannot be read, default `inbound`); for `hmac`, `basic`, `api_key` and
     * `none` `event_type_field` (the body field of the event type, `.` for nested fields) and `id_header` (the header that
     * identifies duplicates); for `hmac` `header` (required), `algorithm` (`sha256`), `encoding` (`hex`, default, or `base64`)
     * and `prefix` (such as `sha256=`); for `api_key` `header` (and `prefix`, such as `Bearer `) or `query`; for `meta`
     * `verify_token` (answered when Meta checks the subscription); for `basic` `username` (required). For every provider,
     * `forward_headers` (received headers passed on to the endpoints, up to 20) and `response` (`['status' => 200..299,
     * 'content_type', 'body']`, the reply when a webhook is accepted). `status`: `active` (default) or `paused`.
     *
     * @param array{name: string, provider: 'stripe'|'github'|'shopify'|'slack'|'meta'|'zoom'|'standard'|'hmac'|'basic'|'api_key'|'none', consumer_id: string, secret?: string,
     *        config?: array{event_type?: string, event_type_field?: string, id_header?: string, header?: string, query?: string, algorithm?: 'sha256', encoding?: 'hex'|'base64', prefix?: string, verify_token?: string, username?: string, forward_headers?: list<string>, response?: array{status: int, content_type: string, body: string}},
     *        status?: 'active'|'paused'} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function create(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/sources', false, body: self::objects(self::pick($params, self::CREATE), ['config']), hasBody: true, options: self::opts($options));
    }

    /**
     * Lists sources, in the order they were created. `foreach` the page to walk every page. Requires `logs:read`.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @return Page<array<string, mixed>>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        $query = self::pick($params, ['limit']);
        $fetch = fn (?string $cursor): array => $this->http->request('GET', '/v1/sources', true, query: [...$query, 'cursor' => $cursor], options: self::opts($options));
        return new Page($fetch($params['cursor'] ?? null), $fetch);
    }

    /**
     * Gets a source. Requires `logs:read`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function get(string $sourceId, array $options = []): array
    {
        return $this->http->request('GET', self::path($sourceId), true, options: self::opts($options));
    }

    /**
     * Updates a source. Only the keys you pass are sent. Changing `provider` without `config` resets the config to its defaults.
     * The signing secret is changed with `setSecret()`. Requires `endpoints:write`.
     *
     * @param array{name?: string, provider?: 'stripe'|'github'|'shopify'|'slack'|'meta'|'zoom'|'standard'|'hmac'|'basic'|'api_key'|'none', consumer_id?: string, status?: 'active'|'paused',
     *        config?: array{event_type?: string, event_type_field?: string, id_header?: string, header?: string, query?: string, algorithm?: 'sha256', encoding?: 'hex'|'base64', prefix?: string, verify_token?: string, username?: string, forward_headers?: list<string>, response?: array{status: int, content_type: string, body: string}}} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function update(string $sourceId, array $params, array $options = []): array
    {
        return $this->http->request('PATCH', self::path($sourceId), true, body: self::object(self::objects(self::pick($params, self::UPDATE), ['config'])), hasBody: true, options: self::opts($options));
    }

    /**
     * Deletes a source and the requests it received. Its URL stops working. Requires `endpoints:write`.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function delete(string $sourceId, array $options = []): void
    {
        $this->http->request('DELETE', self::path($sourceId), true, options: self::opts($options));
    }

    /**
     * Sets the secret that verifies the signatures the source receives (`whsec_...` for `standard`). The previous secret is still
     * accepted for 24 hours. `null` removes the secret. Requires `endpoints:write`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function setSecret(string $sourceId, ?string $secret, array $options = []): array
    {
        return $this->http->request('PUT', self::path($sourceId, '/secret'), true, body: ['secret' => $secret], hasBody: true, options: self::opts($options));
    }

    /**
     * Lists the requests the source received, newest first, within your plan's retention. `foreach` the page to walk every page.
     * `status` lists only `accepted`, `duplicate`, `rejected` or `held` ones.
     * Each is `['id' => 'sreq_...', 'at', 'method', 'status' => 'accepted'|'duplicate'|'rejected'|'held', 'response_status',
     * 'reason' => string|null, 'message_id' => string|null, 'event_type' => string|null, 'content_type' => string|null, 'size',
     * 'headers', 'processed_at' => int|null, 'reprocessable' => bool]`. `held` was received while the source was paused.
     * Requires `logs:read`.
     *
     * @param array{limit?: int, cursor?: string, status?: 'accepted'|'duplicate'|'rejected'|'held'} $params
     * @return Page<array<string, mixed>>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function listRequests(string $sourceId, array $params = [], array $options = []): Page
    {
        $query = self::pick($params, ['limit', 'status']);
        $fetch = fn (?string $cursor): array => $this->http->request('GET', self::path($sourceId, '/requests'), true, query: [...$query, 'cursor' => $cursor], options: self::opts($options));
        return new Page($fetch($params['cursor'] ?? null), $fetch);
    }

    /**
     * Processes a rejected request again (`reprocessable` is true) with the source's current secret and settings, such as
     * after fixing a wrong secret. Returns the request with its new status. A request that was not rejected, or whose body
     * was not kept, throws `ConflictException`. Not retried on a 5xx. Requires `messages:send`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function reprocessRequest(string $sourceId, string $requestId, array $options = []): array
    {
        return $this->http->request('POST', self::path($sourceId, '/requests/' . self::id($requestId) . '/reprocess'), false, options: self::opts($options));
    }
}
