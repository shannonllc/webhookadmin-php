<?php

declare(strict_types=1);

namespace WebhookAdmin;

use WebhookAdmin\Exception\WebhookAdminException;
use WebhookAdmin\Exception\WebhookVerificationException;

/**
 * Verifies webhooks sent by Webhook Admin (Standard Webhooks).
 *
 *     webhook-id / webhook-timestamp (seconds) / webhook-signature: "v1,<base64>" (several, space-separated, during a rotation)
 *
 * The signed content is `{id}.{timestamp}.{body}`; the key is the base64-decoded part after `whsec_`.
 */
class Webhook
{
    private const PREFIX = 'whsec_';
    private const MAX_SAFE_INTEGER = 9007199254740991;

    private readonly string $key;

    /**
     * @param string $secret The endpoint's signing secret (`whsec_...`).
     * @param int $tolerance Allowed clock difference in seconds, both ways. Default 300.
     */
    public function __construct(string $secret, public readonly int $tolerance = 300)
    {
        if ($secret === '') {
            throw new WebhookAdminException('Webhook secret is required');
        }
        $key = self::base64Decode(str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret);
        if ($key === null) {
            throw new WebhookAdminException('Webhook secret is not valid base64 (expected whsec_...)');
        }
        $this->key = $key;
    }

    private static function base64Decode(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? null : $out;
    }

    /** Current Unix time in seconds. */
    protected function now(): int
    {
        return time();
    }

    /**
     * Case-insensitive lookup. `$headers` is an array of name => value (a list value uses the first item), a PSR-7
     * message, or `$_SERVER` (`HTTP_WEBHOOK_ID`).
     *
     * @param array<string, string|list<string>>|object $headers
     */
    private static function header(array|object $headers, string $name): ?string
    {
        if (is_object($headers) && method_exists($headers, 'getHeaderLine')) {
            $v = $headers->getHeaderLine($name);
            // PSR-7 joins several values with ", "; a signature list is space-separated, so this is harmless
            return $v === '' ? null : $v;
        }
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ((array) $headers as $k => $v) {
            $k = (string) $k;
            if (strcasecmp($k, $name) === 0 || $k === $server) {
                if (is_array($v)) {
                    $v = $v[0] ?? null;
                }
                return $v === null ? null : (string) $v;
            }
        }
        return null;
    }

    private function mac(string $msgId, string $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $msgId . '.' . $timestamp . '.' . $payload, $this->key, true);
    }

    /**
     * Verifies the signature and timestamp, then returns the decoded JSON body (associative arrays).
     *
     * @param string $payload The raw request body, exactly as received (for example `file_get_contents('php://input')`).
     * @param array<string, string|list<string>>|object $headers
     * @throws WebhookVerificationException when verification fails.
     */
    public function verify(string $payload, array|object $headers): mixed
    {
        $msgId = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signature = self::header($headers, 'webhook-signature');
        if ($msgId === null || $msgId === '' || $timestamp === null || $timestamp === '' || $signature === null || $signature === '') {
            throw new WebhookVerificationException('Missing required headers');
        }
        if (preg_match('/^[0-9]+$/', $timestamp) !== 1 || strlen($timestamp) > 16 || (int) $timestamp > self::MAX_SAFE_INTEGER) {
            throw new WebhookVerificationException('Invalid webhook-timestamp header');
        }
        $ts = (int) $timestamp;
        $now = $this->now();
        if ($now - $ts > $this->tolerance) {
            throw new WebhookVerificationException('Message timestamp too old');
        }
        if ($ts - $now > $this->tolerance) {
            throw new WebhookVerificationException('Message timestamp too new');
        }

        $expected = $this->mac($msgId, $timestamp, $payload);
        foreach (explode(' ', $signature) as $part) {
            $pieces = explode(',', $part, 2);
            if (count($pieces) !== 2 || $pieces[0] !== 'v1' || $pieces[1] === '') {
                continue;
            }
            $given = self::base64Decode($pieces[1]);
            if ($given !== null && hash_equals($expected, $given)) {
                try {
                    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new WebhookVerificationException('Payload is not valid JSON', previous: $e);
                }
            }
        }
        throw new WebhookVerificationException('No matching signature found');
    }

    /**
     * Computes the `webhook-signature` value (`v1,<base64>`). Useful for tests.
     *
     * @param int|\DateTimeInterface $timestamp Unix seconds or a date.
     */
    public function sign(string $msgId, int|\DateTimeInterface $timestamp, string $payload): string
    {
        $ts = $timestamp instanceof \DateTimeInterface ? $timestamp->getTimestamp() : $timestamp;
        return 'v1,' . base64_encode($this->mac($msgId, (string) $ts, $payload));
    }
}
