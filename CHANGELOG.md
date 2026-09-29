# Changelog

## 0.2.0 (2026-09-29)

- Polling endpoints: `endpoints->create(['consumer_id' => ..., 'type' => 'polling'])` (no `url`), `endpoints->listPollerTokens($id)`, `endpoints->createPollerToken($id, ['name'?])` and `endpoints->deletePollerToken($id, $tokenId)`. `new Poller($endpointId, $token)` fetches the messages as the receiver with a poller token: `poll(['iterator'?, 'limit'?])`, `pages()` and `messages()` (generators). Endpoints have `type` (`http` or `polling`) and `last_polled_at`, the created endpoint and `endpoints->get()` have `poller_url`, `url` is `null` for a polling endpoint, deliveries can be `waiting` and attempts have `via` `poll`.
- Destinations other than webhooks: `type` and `destination` on `endpoints->create()` (`url` is only needed for webhooks) and `destination` on `endpoints->update()`. Endpoints gain `type` and `destination` (settings and `credentials_hint`), attempts gain `ref` and the destination types in `via`. `endpoints->testDestination(['endpoint_id'?, 'type'?, 'destination'?, 'fixed_ip'?])` for `POST /v1/destinations/test`.
- Transformations: `endpoints->getTransformation($id)`, `endpoints->setTransformation($id, ['code' => ...])`, `endpoints->deleteTransformation($id)` and `endpoints->testTransformation($id, ['code'?, 'payload'?, 'event_type'?])` for `/v1/endpoints/:id/transformation`. Endpoints gain `transformation_updated_at`, attempts gain `transform`, and messages and deliveries can have the `cancelled` status.
- `ordering` on `endpoints->create()` and `endpoints->update()`, and on the returned endpoints: `fifo` sends deliveries to that endpoint one at a time, in the order the messages were accepted. `endpoints->get()` has `waiting_count`, the number waiting behind the delivery being sent.
- `secret` on `endpoints->create()`: import an existing signing secret (`whsec_...`, such as one from another webhook service) instead of generating one.
- `body_format` on `endpoints->create()` and `endpoints->update()`, and on the returned endpoints: `raw` sends the message `data` itself as the body (signed as sent) instead of `{"type", "timestamp", "data"}`.
- `transformations_params` on `messages->send()`, passed to transformations as `webhook.transformationsParams`.
- Transformations: they gain `enabled` and `variables` (read as `webhook.env`). `endpoints->setTransformation($id, ['code'?, 'enabled'?, 'variables'?])` sends only the keys you pass, so `code` is optional once saved. `endpoints->testTransformation()` takes `variables` and `transformations_params`. Empty arrays for `variables` and `transformations_params` are sent as `{}`. The code limit is now 51,200 characters.
- `fixed_ip` on `endpoints->update()`.
- `since` on `messages->list()`: only messages created at or after a time (Unix milliseconds).
- `event_types` and `after` on `Poller->poll()`, `pages()` and `messages()`: read only some event types, or start from a time (`DateTimeInterface` or ISO 8601) when there is no iterator. List values in query strings are sent as the same key repeated.

## 0.1.0

- First release: messages, consumers, endpoints (including bulk resend, retry policy and compatibility signature), deliveries, event types, consumer portal links, and webhook signature verification. Same API coverage as the Node.js SDK 0.3.0.
