<?php

declare(strict_types=1);

namespace WebhookAdmin\Http;

use WebhookAdmin\Exception\ConnectionException;
use WebhookAdmin\Exception\TimeoutException;

/** Default transport (ext-curl). Does not follow redirects. */
final class CurlTransport implements Transport
{
    public function send(Request $request): Response
    {
        $ch = curl_init($request->url);
        $headers = [];
        foreach ($request->headers as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->method === '' ? 'GET' : $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue)
                }
                return strlen($line);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutException(sprintf('Request timed out after %s s', self::seconds($request->timeout)));
        }
        if ($errno !== 0 || !is_string($body)) {
            throw new ConnectionException('Connection error: ' . $error);
        }
        return new Response($status, $responseHeaders, $body);
    }

    private static function seconds(float $s): string
    {
        return rtrim(rtrim(sprintf('%.3f', $s), '0'), '.');
    }
}
