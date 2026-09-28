<?php

declare(strict_types=1);

namespace WebhookAdmin\Resources;

use WebhookAdmin\Exception\ConflictException;

final class EventTypes extends Resource
{
    /**
     * Lists the event types of the key's environment, by name (not paged). Any API key of the environment can call it.
     *
     * @return array{items: list<array{name: string, description: string, archived_at: int|null}>}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function list(array $options = []): array
    {
        return $this->http->request('GET', '/v1/event-types', true, options: self::opts($options));
    }

    /**
     * Registers an event type. A duplicate `name` throws `ConflictException`. Requires `endpoints:write`.
     *
     * @param array{name: string, description?: string} $params
     * @return array{name: string, description: string, archived_at: int|null}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function create(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/event-types', false, body: self::pick($params, ['name', 'description']), hasBody: true, options: self::opts($options));
    }

    /**
     * Registers the event types that do not exist yet, and leaves existing ones unchanged (their description included).
     * Safe to call on every deploy. Requires `endpoints:write`.
     *
     * @param list<string|array{name: string, description?: string}> $types
     * @return array{created: list<array<string, mixed>>, existing: list<array<string, mixed>>}
     * @param array{timeout?: float|int, max_retries?: int} $options
     */
    public function ensure(array $types, array $options = []): array
    {
        $wanted = [];
        foreach ($types as $t) {
            $p = is_string($t) ? ['name' => $t] : $t;
            $wanted[$p['name']] ??= $p;
        }
        $known = [];
        foreach ($this->list($options)['items'] as $t) {
            $known[$t['name']] = $t;
        }
        $created = [];
        $existing = [];
        $raced = [];
        foreach ($wanted as $name => $p) {
            if (isset($known[$name])) {
                $existing[] = $known[$name];
                continue;
            }
            try {
                $created[] = $this->create($p, $options);
            } catch (ConflictException) {
                // registered by another caller after the list was read
                $raced[] = (string) $name;
            }
        }
        if ($raced !== []) {
            foreach ($this->list($options)['items'] as $t) {
                if (in_array($t['name'], $raced, true)) {
                    $existing[] = $t;
                }
            }
        }
        return ['created' => $created, 'existing' => $existing];
    }
}
