<?php

declare(strict_types=1);

namespace WebhookAdmin\Http;

use WebhookAdmin\Exception\ApiException;
use WebhookAdmin\Exception\AuthenticationException;
use WebhookAdmin\Exception\ConflictException;
use WebhookAdmin\Exception\ConnectionException;
use WebhookAdmin\Exception\NotFoundException;
use WebhookAdmin\Exception\PermissionException;
use WebhookAdmin\Exception\PlanLimitException;
use WebhookAdmin\Exception\RateLimitException;
use WebhookAdmin\Exception\TimeoutException;
use WebhookAdmin\Exception\ValidationException;
use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Version;

/**
 * Calls `/v1`: retries (429, 5xx, connection errors), timeouts and error mapping.
 *
 * @internal
 */
final class HttpClient
{
    /** Longest wait honoured for 429, in seconds (the rate limit window is one minute). */
    public const MAX_RATE_LIMIT_WAIT = 60.0;
    private const BACKOFF_BASE = 0.5;
    private const BACKOFF_MAX = 8.0;

    /** @var \Closure(float): void */
    public \Closure $sleep;
    /** @var \Closure(): float */
    public \Closure $random;
    /** @var \Closure(): float */
    public \Closure $clock;

    public function __construct(
        private readonly string $apiKey,
        public readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly Transport $transport,
    ) {
        $this->sleep = static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
        $this->random = static fn (): float => mt_rand() / mt_getrandmax();
        $this->clock = static fn (): float => microtime(true);
    }

    /** Seconds: exponential backoff with ±25% jitter. `$attempt` starts at 0. */
    public static function backoff(int $attempt, callable $random): float
    {
        $base = min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** $attempt));
        return round($base * (0.75 + $random() * 0.5), 3);
    }

    /** Seconds from `retry-after` (seconds or HTTP date). */
    public static function parseRetryAfter(?string $value, float $now): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \\G\\M\\T', $value, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }
        return max(0.0, (float) $at->getTimestamp() - $now);
    }

    /** Seconds to wait before retrying a 429: `retry-after`, then `x-ratelimit-reset` (Unix seconds). */
    public static function rateLimitWait(Response $res, float $now): ?float
    {
        $after = self::parseRetryAfter($res->header('retry-after'), $now);
        if ($after !== null) {
            return $after;
        }
        $reset = $res->header('x-ratelimit-reset');
        if ($reset !== null && $reset !== '' && is_numeric($reset)) {
            return max(0.0, (float) $reset - $now);
        }
        return null;
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string> $headers
     * @param array{timeout?: float|int|null, max_retries?: int|null} $options
     * @param bool $idempotent Safe to send twice. 5xx and connection errors are retried only for these.
     *                         429 is always retried: the API rejects it before doing anything.
     */
    public function request(
        string $method,
        string $path,
        bool $idempotent,
        array $query = [],
        mixed $body = null,
        bool $hasBody = false,
        array $headers = [],
        array $options = [],
    ): mixed {
        $retries = $options['max_retries'] ?? $this->maxRetries;
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $payload = null;
        if ($hasBody) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        for ($attempt = 0; ; $attempt++) {
            $o = $this->once($method, $path, $query, $payload, $headers, $timeout, $idempotent);
            if ($o->error === null) {
                return $o->value;
            }
            if (!$o->retryable || $attempt >= $retries) {
                throw $o->error;
            }
            ($this->sleep)($o->wait ?? self::backoff($attempt, $this->random));
        }
    }

    /** @param array<string, scalar|null> $query */
    public function url(string $path, array $query = []): string
    {
        $q = [];
        foreach ($query as $k => $v) {
            if ($v !== null && $v !== '') {
                $q[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }
        $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        return $this->baseUrl . $path . ($qs === '' ? '' : '?' . $qs);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string> $extra
     */
    private function once(string $method, string $path, array $query, ?string $payload, array $extra, float $timeout, bool $idempotent): Outcome
    {
        $headers = [
            'authorization' => 'Bearer ' . $this->apiKey,
            'accept' => 'application/json',
            'user-agent' => Version::USER_AGENT,
        ];
        foreach ($extra as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
        if ($payload !== null) {
            $headers['content-type'] = 'application/json';
        }
        try {
            $res = $this->transport->send(new Request($method, $this->url($path, $query), $headers, $payload, $timeout));
        } catch (TimeoutException $e) {
            return Outcome::fail($e, $idempotent);
        } catch (ConnectionException $e) {
            return Outcome::fail($e, $idempotent);
        } catch (WebhookAdminException $e) {
            return Outcome::fail($e, false);
        } catch (\Throwable $e) {
            return Outcome::fail(new ConnectionException('Connection error: ' . $e->getMessage(), previous: $e), $idempotent);
        }

        if ($res->status >= 200 && $res->status < 300) {
            if ($res->body === '') {
                return Outcome::ok(null);
            }
            try {
                return Outcome::ok(json_decode($res->body, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException $e) {
                $err = new ApiException(sprintf('Invalid JSON in response (HTTP %d)', $res->status), $res->status, null, null, self::requestIdOf($res), $e);
                return Outcome::fail($err, false);
            }
        }
        $error = self::errorFromResponse($res, ($this->clock)());
        if ($res->status === 429) {
            $wait = self::rateLimitWait($res, ($this->clock)());
            if ($wait !== null && $wait > self::MAX_RATE_LIMIT_WAIT) {
                return Outcome::fail($error, false);
            }
            return Outcome::fail($error, true, $wait);
        }
        return Outcome::fail($error, $res->status >= 500 && $idempotent);
    }

    public static function requestIdOf(Response $res): ?string
    {
        $id = $res->header('x-request-id');
        if ($id !== null && $id !== '') {
            return $id;
        }
        $ray = $res->header('cf-ray');
        return $ray === '' ? null : $ray;
    }

    /** Builds the exception for a non-2xx response. */
    public static function errorFromResponse(Response $res, float $now): WebhookAdminException
    {
        try {
            $parsed = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parsed = null; // HTML from a proxy, empty body, etc.
        }
        $body = is_array($parsed) ? $parsed : [];
        $code = isset($body['error']) && is_string($body['error']) ? $body['error'] : null;
        $message = isset($body['message']) && is_string($body['message']) ? $body['message'] : 'HTTP ' . $res->status;
        $fields = isset($body['fields']) && is_array($body['fields']) ? array_map(static fn ($v): string => is_scalar($v) ? (string) $v : (string) json_encode($v), $body['fields']) : null;
        $args = [$message, $res->status, $code, $fields, self::requestIdOf($res)];
        return match (true) {
            $res->status === 401 => new AuthenticationException(...$args),
            $res->status === 403 => new PermissionException(...$args),
            $res->status === 404 => new NotFoundException(...$args),
            in_array($res->status, [400, 413, 422], true) => new ValidationException(...$args),
            $res->status === 402 => new PlanLimitException(...$args),
            $res->status === 409 => new ConflictException(...$args),
            $res->status === 429 => new RateLimitException(...[...$args, null, self::parseRetryAfter($res->header('retry-after'), $now)]),
            default => new ApiException(...$args),
        };
    }
}
