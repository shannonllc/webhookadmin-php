<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Http\HttpClient;

/**
 * Per-call options accepted by every method as the last argument:
 * `['timeout' => seconds, 'max_retries' => int]`.
 *
 * Params are arrays with the API's field names (docs/api.md). Keys you leave out are not sent;
 * a key set to `null` is sent as JSON `null`.
 *
 * @internal
 */
abstract class Resource
{
    public function __construct(protected readonly HttpClient $http)
    {
    }

    protected static function id(string $v): string
    {
        return rawurlencode($v);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    protected static function pick(array $params, array $allowed): array
    {
        return array_intersect_key($params, array_flip($allowed));
    }

    /**
     * A JSON object even when empty (an empty PHP array would encode as `[]`).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|\stdClass
     */
    protected static function object(array $params): array|\stdClass
    {
        return $params === [] ? new \stdClass() : $params;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{timeout?: float|int, max_retries?: int}
     */
    protected static function opts(array $options): array
    {
        return self::pick($options, ['timeout', 'max_retries']);
    }
}
