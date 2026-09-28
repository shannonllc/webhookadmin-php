<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Exception\ConflictException;

final class Deliveries extends Resource
{
    /**
     * Sends a finished delivery again. A delivery still being sent throws `ConflictException`. Requires `messages:retry`.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function retry(string $deliveryId, array $options = []): void
    {
        $this->http->request('POST', '/v1/deliveries/' . self::id($deliveryId) . '/retry', false, options: self::opts($options));
    }
}
