# Changelog

## Unreleased

- Transformations: `endpoints->getTransformation($id)`, `endpoints->setTransformation($id, ['code' => ...])`, `endpoints->deleteTransformation($id)` and `endpoints->testTransformation($id, ['code'?, 'payload'?, 'event_type'?])` for `/v1/endpoints/:id/transformation`. Endpoints gain `transformation_updated_at`, attempts gain `transform`, and messages and deliveries can have the `cancelled` status.

## 0.1.0

- First release: messages, consumers, endpoints (including bulk resend, retry policy and compatibility signature), deliveries, event types, consumer portal links, and webhook signature verification. Same API coverage as the Node.js SDK 0.3.0.
