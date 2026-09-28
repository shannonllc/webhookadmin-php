# Changelog

## Unreleased

- Polling endpoints: `endpoints->create(['consumer_id' => ..., 'type' => 'polling'])` (no `url`), `endpoints->listPollerTokens($id)`, `endpoints->createPollerToken($id, ['name'?])` and `endpoints->deletePollerToken($id, $tokenId)`. `new Poller($endpointId, $token)` fetches the messages as the receiver with a poller token: `poll(['iterator'?, 'limit'?])`, `pages()` and `messages()` (generators). Endpoints have `type` (`http` or `polling`) and `last_polled_at`, the created endpoint and `endpoints->get()` have `poller_url`, `url` is `null` for a polling endpoint, deliveries can be `waiting` and attempts have `via` `poll`.
- Destinations other than webhooks: `type` and `destination` on `endpoints->create()` (`url` is only needed for webhooks) and `destination` on `endpoints->update()`. Endpoints gain `type` and `destination` (settings and `credentials_hint`), attempts gain `ref` and the destination types in `via`. `endpoints->testDestination(['endpoint_id'?, 'type'?, 'destination'?, 'fixed_ip'?])` for `POST /v1/destinations/test`.
- Transformations: `endpoints->getTransformation($id)`, `endpoints->setTransformation($id, ['code' => ...])`, `endpoints->deleteTransformation($id)` and `endpoints->testTransformation($id, ['code'?, 'payload'?, 'event_type'?])` for `/v1/endpoints/:id/transformation`. Endpoints gain `transformation_updated_at`, attempts gain `transform`, and messages and deliveries can have the `cancelled` status.

## 0.1.0

- First release: messages, consumers, endpoints (including bulk resend, retry policy and compatibility signature), deliveries, event types, consumer portal links, and webhook signature verification. Same API coverage as the Node.js SDK 0.3.0.
