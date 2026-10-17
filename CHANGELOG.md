# Changelog

## 0.3.1 (2026-10-17)

- Republished from a cleaned history. No code changes.

## 0.3.0 (2026-10-17)

- Comments and tests only: the request ID is described as the ID to quote when contacting support, and internal paths and local addresses were removed from comments and tests. No API changes.
- Retrying a delivery to a disabled endpoint (`deliveries->retry()`), sending a test to one (`endpoints->sendTest()`) and starting a bulk resend for one (`endpoints->recover()`) now throw `ConflictException` with `errorCode` `endpoint_disabled` instead of being accepted and then not sent. Fix the endpoint and set `status` to `active` first. Retrying a delivery whose endpoint was deleted throws `NotFoundException`. Each delivery in `messages->get()` has `endpoint_status` (the endpoint's current status, or `null` if deleted).
- Messages can have the `no_endpoint` status: the consumer had no endpoint for the message when it was accepted. Such messages used to be listed as `pending`. They are stored but not sent (not even if an endpoint is added later) and are not counted toward the monthly quota. `messages->list(['status' => 'no_endpoint'])` lists them. If you branch on `status`, handle the new value.
- `consumers->delete($id)` for `DELETE /v1/consumers/:id`: deletes a consumer with its endpoints and the sources that deliver to it. Portal links for the consumer stop working and deliveries not yet sent are not sent.
- Sources: receive webhooks from other services (Stripe, GitHub, Shopify, Slack, Meta, Zoom, Standard Webhooks, a custom HMAC header, Basic authentication, an API key, or no verification) at a URL of their own, verify them and turn them into messages of a consumer. `$client->sources` with `create(['name', 'provider', 'consumer_id', 'secret'?, 'config'?, 'status'?])`, `list(['limit'?, 'cursor'?])`, `get($id)`, `update($id, ['name'?, 'provider'?, 'consumer_id'?, 'status'?, 'config'?])`, `delete($id)`, `setSecret($id, $secret)` (`null` removes the secret), `listRequests($id, ['limit'?, 'cursor'?, 'status'?])` and `reprocessRequest($id, $requestId)` (not retried on a 5xx) for `/v1/sources`. An empty `config` is sent as `{}`. `config` takes `query`, `verify_token`, `username`, `forward_headers` and `response` besides the `hmac` settings. Sources have `held` (the number of webhooks held while paused), and received requests have `method`, `processed_at`, `reprocessable` and the `held` status. A paused source accepts and holds webhooks, and sends them on when set back to `active`.
- `messages->get()` has `source` (`['id', 'name', 'request_id']`), the source the message was received from, or `null`.
- Chat destinations: the `slack`, `teams` and `chatwork` endpoint types (Pro plan and above) post a chat message built from the message `data` (`text`, and optionally `title`, `lines` and `link`; otherwise the event type and `data` as JSON) instead of the JSON body, without a signature. `slack` and `teams` take `'destination' => ['credentials' => ['webhook_url' => ...]]` (a Slack Incoming Webhook URL, or a Teams Workflows URL on `*.powerplatform.com` or `*.logic.azure.com`); `chatwork` takes `['room_id' => ..., 'credentials' => ['api_token' => ...]]`. An attempt's `via` can be `slack`, `teams` or `chatwork`, and `ref` is the Chatwork `message_id` (`null` for `slack` and `teams`).

## 0.2.1 (2026-09-29)

- Documentation only: the README and the type descriptions no longer name other webhook services. No API changes.

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
