<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Exception\ConflictException;

final class Deliveries extends Resource
{
    /**
     * Sends a finished delivery again. A delivery still being sent throws `ConflictException` (`errorCode` `conflict`).
     * If the endpoint is disabled, throws `ConflictException` with `errorCode` `endpoint_disabled` and nothing is sent:
     * fix the endpoint, set `status` to `active` with `endpoints->update()`, then retry. A paused endpoint holds the
     * retry until it is resumed. A deleted endpoint throws `NotFoundException`. Requires `messages:retry`.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function retry(string $deliveryId, array $options = []): void
    {
        $this->http->request('POST', '/v1/deliveries/' . self::id($deliveryId) . '/retry', false, options: self::opts($options));
    }
}
