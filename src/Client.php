<?php

declare(strict_types=1);

namespace WebhookAdmin;

use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Http\CurlTransport;
use WebhookAdmin\Http\HttpClient;
use WebhookAdmin\Http\Transport;
use WebhookAdmin\Resources\Consumers;
use WebhookAdmin\Resources\Deliveries;
use WebhookAdmin\Resources\Endpoints;
use WebhookAdmin\Resources\EventTypes;
use WebhookAdmin\Resources\Messages;
use WebhookAdmin\Resources\Portal;

/** Client for the Webhook Admin API (`/v1`). */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.webhookadmin.com';
    /** Seconds per attempt. */
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const API_KEY_ENV = 'WEBHOOK_ADMIN_API_KEY';

    public readonly Messages $messages;
    public readonly Consumers $consumers;
    public readonly Endpoints $endpoints;
    public readonly Deliveries $deliveries;
    public readonly EventTypes $eventTypes;
    public readonly Portal $portal;
    public readonly string $baseUrl;
    /** @internal */
    public readonly HttpClient $http;

    /**
     * @param string|null $apiKey `sk_live_...` or `sk_test_...`. Defaults to the `WEBHOOK_ADMIN_API_KEY` environment variable.
     * @param array{base_url?: string, timeout?: float|int, max_retries?: int, transport?: Transport} $options
     *        `timeout` is seconds per attempt (default 30), `max_retries` the retries after the first attempt (default 2),
     *        `transport` a custom transport (default: cURL).
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? trim((string) getenv(self::API_KEY_ENV));
        if ($key === '') {
            throw new WebhookAdminException("Missing API key. Pass it to new Client('sk_...') or set " . self::API_KEY_ENV . '.');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->http = new HttpClient(
            $key,
            $this->baseUrl,
            (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES),
            $options['transport'] ?? new CurlTransport(),
        );
        $this->messages = new Messages($this->http);
        $this->consumers = new Consumers($this->http);
        $this->endpoints = new Endpoints($this->http);
        $this->deliveries = new Deliveries($this->http);
        $this->eventTypes = new EventTypes($this->http);
        $this->portal = new Portal($this->http);
    }
}
