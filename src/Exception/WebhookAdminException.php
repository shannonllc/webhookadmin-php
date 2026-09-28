<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/**
 * Base class for every exception thrown by the SDK.
 *
 * The API error code is `errorCode` (`Exception::getCode()` is an integer and is not used).
 */
class WebhookAdminException extends \RuntimeException
{
    /**
     * @param int|null $status HTTP status. Null when no response was received.
     * @param string|null $errorCode API error code, such as `invalid` or `not_found`.
     * @param array<string, string>|null $fields Per-field messages for validation errors.
     * @param string|null $requestId Request ID (`x-request-id`, or Cloudflare's `cf-ray`) to quote when contacting support.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?array $fields = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string, string>|null */
    public function getFields(): ?array
    {
        return $this->fields;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
