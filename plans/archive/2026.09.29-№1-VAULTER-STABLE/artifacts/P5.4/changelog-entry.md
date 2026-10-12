### Standard Webhooks delivery 1.0 (PLAN2.P5.4)

- Changed (breaking): deliveries are signed per Standard Webhooks. Headers are exactly `Content-Type`,
  `User-Agent: Vaulter-Webhooks/1`, `webhook-id` (the envelope `id`, the same on every retry),
  `webhook-timestamp` (Unix seconds) and `webhook-signature` (`v1,` + base64 HMAC-SHA256 over
  `"{id}.{timestamp}.{body}"`; two signatures while a secret rotates). `X-Vaulter-Signature`,
  `X-Vaulter-Timestamp` and `X-Vaulter-Event` are gone, and so is the hex signature over
  `"{timestamp}.{body}"`.
- Changed (breaking): signing secrets are `whsec_` + base64 key. Migration
  `2026_10_10_000003_webhook_endpoints_v1` converts every stored secret to `whsec_` + base64 of the old
  secret, so the HMAC key keeps the same bytes (all or nothing, idempotent, reversible).
  `WebhookSigner::sign()` now takes `(webhookId, timestamp, body, secret)`.
- Added: `WebhookSecret`, `StandardWebhookVerifier` (±300 s tolerance, several secrets and signatures,
  constant-time compare) and `InvalidWebhookSignatureException` for receivers.
- Added: `RotateWebhookSecretAction` (`RotatedWebhookSecret`) with an overlap window in which both
  secrets sign; columns `previous_signing_secret`, `previous_secret_expires_at`.
- Changed (breaking): retry policy — `webhooks.retry_schedule` (5 s … 24 h, ten attempts, about three
  days) with `webhooks.retry_jitter`, timeout 15 s. `webhooks.max_attempts`, `backoff`,
  `disable_after_failures`, `signature_header`, `timestamp_header` and `event_header` are removed and
  fail boot with `InvalidConfigurationException` naming the replacement.
- Changed (breaking): an endpoint is disabled after `webhooks.disable_after_days` (5) of unbroken
  failure (`failing_since`, `disabled_at`, `disabled_reason` = `failing`|`manual`), not after N dead
  deliveries; its open deliveries become `dead` (`endpoint_disabled`) and
  `Vaulter\Webhooks\Events\WebhookEndpointDisabled` is dispatched after commit. Deactivating or
  updating an endpoint to inactive does the same with reason `manual`.
- Added: `RedeliverWebhookAction`, `WebhookDeliveryNotRedeliverableException`
  (`webhook_delivery_not_redeliverable`, 409) and
  `php artisan vaulter:webhooks:redeliver {delivery?} {--endpoint=} {--since=} {--status=dead}`.
- Changed: `WebhookEndpoint` hides `signing_secret` and `previous_signing_secret` from `toArray()`.
