<?php

declare(strict_types=1);

namespace WebhookAdmin\Http;

/** What a transport sends. */
final class Request
{
    /**
     * @param array<string, string> $headers Lowercase names.
     * @param float $timeout Seconds for this attempt.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }
}
