<?php

declare(strict_types=1);

namespace WebhookAdmin\Http;

/** What a transport returns. */
final class Response
{
    /** @var array<string, string> Lowercase names. */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
