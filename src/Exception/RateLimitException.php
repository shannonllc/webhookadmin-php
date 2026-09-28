<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 429: too many requests. Thrown after retries are used up. */
class RateLimitException extends WebhookAdminException
{
    /**
     * @param float|null $retryAfter Seconds to wait before retrying, from `retry-after`.
     * @param array<string, string>|null $fields
     */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        ?array $fields = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status, $errorCode, $fields, $requestId, $previous);
    }
}
