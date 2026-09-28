<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Page;

final class Messages extends Resource
{
    /**
     * Sends a message to every active endpoint of the consumer that accepts the event type.
     * Requires the `messages:send` scope.
     *
     * @param array{consumer: string, event_type: string, payload: mixed, transformations_params?: array<string, mixed>} $params
     *        `consumer` is your customer's ID (`external_id`); the consumer is created if it does not exist.
     *        `transformations_params` (a JSON object, up to 4 KB as JSON) is passed to the endpoints' transformations as
     *        `webhook.transformationsParams`.
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $options `idempotency_key` (1 to 256
     *        characters) returns the same message for 24 hours. Generated when omitted, and reused across retries.
     * @return array{id: string, deliveries: int}
     */
    public function send(array $params, array $options = []): array
    {
        $key = $options['idempotency_key'] ?? 'webhookadmin-php-' . self::uuid();
        $body = self::objects(self::pick($params, ['consumer', 'event_type', 'payload', 'transformations_params']), ['transformations_params']);
        return $this->http->request('POST', '/v1/messages', true, body: $body, hasBody: true, headers: ['idempotency-key' => $key], options: self::opts($options));
    }

    /**
     * Lists messages, newest first. `foreach` the page to walk every page. Requires `logs:read`.
     * `since`: Unix milliseconds; only messages created at or after it. When omitted with `q`, `status` or `event_type`,
     * the last 7 days are searched.
     *
     * @param array{status?: string, event_type?: string, q?: string, since?: int, limit?: int, cursor?: string} $params
     * @return Page<array<string, mixed>>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        $query = self::pick($params, ['status', 'event_type', 'q', 'since', 'limit']);
        $fetch = fn (?string $cursor): array => $this->http->request('GET', '/v1/messages', true, query: [...$query, 'cursor' => $cursor], options: self::opts($options));
        return new Page($fetch($params['cursor'] ?? null), $fetch);
    }

    /**
     * Gets a message with its deliveries and attempts. Requires `logs:read`.
     *
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function get(string $messageId, array $options = []): array
    {
        return $this->http->request('GET', '/v1/messages/' . self::id($messageId), true, options: self::opts($options));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
