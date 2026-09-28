<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

final class Portal extends Resource
{
    /**
     * Creates a 15-minute link to the consumer portal, where your customer manages their own endpoints.
     * Starter plan or above. Requires `endpoints:write`.
     *
     * @param array{frame_origin?: string, locale?: 'ja'|'en'} $params
     * @return array{url: string, expires_at: int}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function createLink(string $consumerId, array $params = [], array $options = []): array
    {
        return $this->http->request('POST', '/v1/consumers/' . self::id($consumerId) . '/portal', true, body: self::object(self::pick($params, ['frame_origin', 'locale'])), hasBody: true, options: self::opts($options));
    }
}
