<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Exception\ConflictException;
use WebhookAdmin\Page;

final class Consumers extends Resource
{
    /**
     * Creates a consumer. A duplicate `external_id` throws `ConflictException`. Requires `consumers:write`.
     *
     * @param array{external_id: string, name?: string} $params
     * @return array<string, mixed>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function create(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/consumers', false, body: self::pick($params, ['external_id', 'name']), hasBody: true, options: self::opts($options));
    }

    /**
     * Lists consumers. `foreach` the page to walk every page. Requires `logs:read`.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @return Page<array<string, mixed>>
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        $query = self::pick($params, ['limit']);
        $fetch = fn (?string $cursor): array => $this->http->request('GET', '/v1/consumers', true, query: [...$query, 'cursor' => $cursor], options: self::opts($options));
        return new Page($fetch($params['cursor'] ?? null), $fetch);
    }
}
