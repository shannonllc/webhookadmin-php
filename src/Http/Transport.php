<?php

declare(strict_types=1);

namespace WebhookAdmin\Http;

/**
 * Sends one request. Throw `TimeoutException` on a timeout; any other exception is treated as a connection error.
 * Implement it to route requests through Guzzle or a PSR-18 client, or to stub the API in tests.
 */
interface Transport
{
    public function send(Request $request): Response;
}
