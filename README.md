# shannonllc/webhookadmin

PHP SDK for [Webhook Admin](https://webhookadmin.com/docs/).

- No Composer dependencies. Uses ext-curl and ext-json.
- PHP 8.1+.

## Install

```sh
composer require shannonllc/webhookadmin
```

## Send a message

```php
use WebhookAdmin\Client;

$wha = new Client('sk_live_...'); // or set WEBHOOK_ADMIN_API_KEY

$res = $wha->messages->send(
    ['consumer' => 'cus_123', 'event_type' => 'invoice.paid', 'payload' => ['invoice_id' => 'inv_1', 'amount' => 1200]],
    ['idempotency_key' => 'invoice-inv_1-paid'],
);
echo $res['id'], ' ', $res['deliveries'];
```

`consumer` is your customer's ID (`external_id`). The consumer is created if it does not exist.

Params and responses are associative arrays with the API's field names. An empty PHP array encodes as a JSON list (`[]`); pass `new \stdClass()` for an empty object payload.

## Register event types

Register the event types you send, so they appear in the dashboard and the consumer portal. `ensure` creates the missing ones and leaves existing ones unchanged, so it can run on every deploy. Requires the `endpoints:write` scope.

```php
$result = $wha->eventTypes->ensure([
    ['name' => 'invoice.paid', 'description' => 'An invoice was paid'],
    'invoice.refunded',
]);
// $result['created'], $result['existing']
```

`eventTypes->create` throws `ConflictException` when the name already exists. `eventTypes->list()` returns every event type as `['items' => [...]]` (not paged), sorted by name. It works with any API key of the environment.

## Verify incoming webhooks

Pass the raw request body, not a decoded array.

```php
use WebhookAdmin\Webhook;
use WebhookAdmin\Exception\WebhookVerificationException;

$wh = new Webhook(getenv('WEBHOOK_SECRET')); // whsec_...

try {
    $event = $wh->verify(file_get_contents('php://input'), $_SERVER); // ['type', 'timestamp', 'data']
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}
http_response_code(204);
```

`verify` throws `WebhookVerificationException` when the signature does not match or the timestamp is more than 5 minutes off (`new Webhook($secret, tolerance: 300)`). While a secret is being rotated, messages carry two signatures and either secret verifies. Headers can be `$_SERVER`, an array of name => value (names are case-insensitive; list values use the first item), or a PSR-7 request.

Laravel:

```php
Route::post('/webhooks', function (Request $request) use ($wh) {
    try {
        $event = $wh->verify($request->getContent(), $request->headers->all());
    } catch (WebhookVerificationException) {
        return response()->noContent(400);
    }
    return response()->noContent();
});
```

## API

| Method | Endpoint |
|---|---|
| `messages->send(['consumer', 'event_type', 'payload'], ['idempotency_key'?])` | `POST /v1/messages` |
| `messages->list(['status'?, 'event_type'?, 'q'?, 'limit'?, 'cursor'?])` | `GET /v1/messages` |
| `messages->get($id)` | `GET /v1/messages/:id` |
| `deliveries->retry($id)` | `POST /v1/deliveries/:id/retry` |
| `consumers->create(['external_id', 'name'?])` | `POST /v1/consumers` |
| `consumers->list(['limit'?, 'cursor'?])` | `GET /v1/consumers` |
| `endpoints->create(['consumer_id', 'url'?, 'type'?, 'destination'?, 'event_types'?, 'fixed_ip'?, 'description'?, 'retry'?, 'compat_signature'?])` | `POST /v1/endpoints` |
| `endpoints->list(['consumer_id'?])` | `GET /v1/endpoints` |
| `endpoints->get($id)` | `GET /v1/endpoints/:id` |
| `endpoints->update($id, ['url'?, 'destination'?, 'event_types'?, 'status'?, 'description'?, 'retry'?, 'compat_signature'?])` | `PATCH /v1/endpoints/:id` |
| `endpoints->testDestination(['endpoint_id'?, 'type'?, 'destination'?, 'fixed_ip'?])` | `POST /v1/destinations/test` |
| `endpoints->delete($id)` | `DELETE /v1/endpoints/:id` |
| `endpoints->rotateSecret($id)` | `POST /v1/endpoints/:id/rotate-secret` |
| `endpoints->sendTest($id, ['event_type'?])` | `POST /v1/endpoints/:id/test` |
| `endpoints->recover($id, ['since'])` | `POST /v1/endpoints/:id/recover` |
| `endpoints->recovery($id, $recoveryId)` | `GET /v1/endpoints/:id/recoveries/:rid` |
| `endpoints->getTransformation($id)` | `GET /v1/endpoints/:id/transformation` |
| `endpoints->setTransformation($id, ['code'])` | `PUT /v1/endpoints/:id/transformation` |
| `endpoints->deleteTransformation($id)` | `DELETE /v1/endpoints/:id/transformation` |
| `endpoints->testTransformation($id, ['code'?, 'payload'?, 'event_type'?])` | `POST /v1/endpoints/:id/transformation/test` |
| `endpoints->listPollerTokens($id)` | `GET /v1/endpoints/:id/poller-tokens` |
| `endpoints->createPollerToken($id, ['name'?])` | `POST /v1/endpoints/:id/poller-tokens` |
| `endpoints->deletePollerToken($id, $tokenId)` | `DELETE /v1/endpoints/:id/poller-tokens/:tid` |
| `portal->createLink($consumerId, ['frame_origin'?, 'locale'?])` | `POST /v1/consumers/:id/portal` |
| `eventTypes->list()` | `GET /v1/event-types` |
| `eventTypes->create(['name', 'description'?])` | `POST /v1/event-types` |
| `eventTypes->ensure([name or ['name', 'description'?], ...])` | `GET` and `POST /v1/event-types` |

Every method also takes per-call options as the last argument: `['timeout' => seconds, 'max_retries' => n]`.

Keys you leave out are not sent. In `endpoints->update`, a key set to `null` is sent as `null`: `'event_types' => null` receives every event type, `'retry' => null` goes back to the default policy, and `'compat_signature' => null` removes it.

### Resend failed deliveries of an endpoint

After a receiver is fixed, resend every delivery to that endpoint that ended `failed`, as the same messages (same `webhook-id`). The endpoint must be `active`.

```php
$recovery = $wha->endpoints->recover('ep_...', ['since' => (time() - 86400) * 1000]);
// later
$r = $wha->endpoints->recovery('ep_...', $recovery['id']); // $r['status']: running, done or stopped
```

Messages created while the endpoint was `disabled` are not covered.

### Polling endpoints

A polling endpoint has no URL: the receiver fetches its messages with a poller token (`sk_poll_...`), which can only read that endpoint. Starter plan and above.

```php
// sender (API key)
$ep = $wha->endpoints->create(['consumer_id' => 'con_...', 'type' => 'polling']);
$token = $wha->endpoints->createPollerToken($ep['id'], ['name' => 'erp'])['token']; // returned only here
```

The receiver needs only the endpoint ID and the token:

```php
use WebhookAdmin\Poller;

$poller = new Poller('ep_...', 'sk_poll_...');
$iterator = loadIterator(); // null the first time: start of your plan's retention
foreach ($poller->pages(['iterator' => $iterator]) as $page) {
    foreach ($page['data'] as $m) {
        handle($m['payload']); // ['type', 'timestamp', 'data']
    }
    saveIterator($page['iterator']);
}
```

`pages()` stops after a page with `done` true (nothing more right now); run it again later with the saved iterator. Delivery is at least once: the next call with a page's `iterator` acknowledges that page, and calling with an older iterator returns the same messages again, so deduplicate by `$m['id']` if needed. `poll(['iterator'?, 'limit'?])` fetches one page (`limit` 1 to 250, default 50), and `messages()` yields the messages of `pages()` one by one. `new Poller()` takes the same options as `Client` (`base_url`, `timeout`, `max_retries`, `transport`). `$m['headers']` carries the same `webhook-id`, `webhook-timestamp` and `webhook-signature` as a pushed webhook; checking the signature is optional, since the connection is already authenticated by the token.

### Retry policy per endpoint

```php
$wha->endpoints->update('ep_...', ['retry' => ['count' => 3, 'interval' => '5m']]);
$wha->endpoints->update('ep_...', ['retry' => null]); // back to the default
```

| Field | Values |
|---|---|
| `count` | `0`, `1`, `2`, `3`, `5`, `7`, `12` (retries after the first send; `0` means no retry) |
| `interval` | `progressive` (5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 10 h, then 10 h each), `1m`, `5m`, `30m`, `1h`, `6h` |

The default is `['count' => 7, 'interval' => 'progressive']`: up to 8 attempts including the first, over about 28 hours. Combinations whose last retry comes more than 3 days after the first send (`progressive` with `12`) are rejected with `ValidationException`.

### Compatibility signature

For receivers that still verify a custom signature, add one extra header. The Standard Webhooks headers are always sent. HMAC-SHA256 only; the key is the endpoint's signing secret string (`whsec_...`) as UTF-8. GitHub style:

```php
$wha->endpoints->update('ep_...', [
    'compat_signature' => ['header' => 'x-hub-signature-256', 'content' => 'body', 'encoding' => 'hex', 'prefix' => 'sha256='],
]);
```

`'content' => 'body'` has no replay protection. Use `timestamp_body` (signs `{webhook-timestamp}.{body}`) if you can, and move receivers to `Webhook::verify()`. Set `'compat_signature' => null` to remove it.

### Pagination

`list()` returns the first page (`$page->items`, `$page->nextCursor`). `foreach` over it walks every item across pages.

```php
foreach ($wha->messages->list(['status' => 'failed']) as $message) {
    echo $message['id'], "\n";
}
```

## Errors

| Class | Status |
|---|---|
| `AuthenticationException` | 401 |
| `PermissionException` | 403 |
| `NotFoundException` | 404 |
| `ValidationException` | 400, 413, 422 |
| `PlanLimitException` | 402 |
| `ConflictException` | 409 |
| `RateLimitException` | 429 (`retryAfter` in seconds) |
| `ApiException` | other, including 5xx |
| `ConnectionException` | no response |
| `TimeoutException` | no response within the timeout (subclass of `ConnectionException`) |

All are in `WebhookAdmin\Exception` and extend `WebhookAdminException`, which has `status`, `errorCode`, `getMessage()`, `fields` and `requestId` (the API error code is `errorCode`, because `Exception::getCode()` is an integer).

```php
use WebhookAdmin\Exception\ValidationException;

try {
    $wha->endpoints->create(['consumer_id' => 'con_...', 'url' => 'http://example.com']);
} catch (ValidationException $e) {
    print_r($e->fields); // ['url' => '...']
}
```

## Retries

| Response | Retried |
|---|---|
| 429 | Always. Waits for `retry-after`, or until `x-ratelimit-reset`. |
| 5xx, connection error, timeout | Only requests that are safe to repeat: `GET`, `PATCH`, `DELETE`, `messages->send`, `portal->createLink` |
| Other 4xx | Never |

Backoff starts at 0.5 s, doubles up to 8 s, with ±25% jitter. `messages->send` generates an `Idempotency-Key` when you omit it and sends the same key on every retry, so a retried send is delivered once. Keys are kept for 24 hours.

## Configuration

```php
$wha = new Client('sk_live_...', [
    'base_url' => 'https://api.webhookadmin.com', // default
    'timeout' => 30, // seconds per attempt, default 30
    'max_retries' => 2, // default 2
    'transport' => $transport, // a WebhookAdmin\Http\Transport, default cURL
]);
```

A transport implements `WebhookAdmin\Http\Transport::send(Request): Response`. Throw `TimeoutException` for a timeout; any other exception is treated as a connection error. Use it to route requests through Guzzle or a PSR-18 client, or to stub the API in tests.

| Environment variable | Used when |
|---|---|
| `WEBHOOK_ADMIN_API_KEY` | the API key argument is omitted |

## License

MIT
