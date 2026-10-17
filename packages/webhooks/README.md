# Vaulter · Webhooks

`axioma-studio/vaulter-webhooks` delivers Vaulter events to external HTTP subscribers: endpoint
management (REST and Artisan), Standard Webhooks signing, SSRF-guarded outbound delivery, retries with
jitter, auto-disable of failing endpoints and a delivery log.

The package is an **outbox consumer** of the Vaulter kernel (`webhooks.fanout`): the core writes every
domain event to the transactional outbox, `vaulter:relay` hands it to the consumer, which fans it out to
the subscribed endpoints. It depends on `axioma-studio/vaulter-core` and `axioma-studio/vaulter-drive`
(endpoints are scoped to drives) and is **opt-in**: it opens outbound network traffic, so the module is
active only after `vaulter.modules.webhooks` is set to `true`.

```php
// config/vaulter.php
'modules' => ['webhooks' => true],
```

Configuration lives in `config/vaulter-webhooks.php` (publish with the `vaulter-webhooks-config` tag);
the module owns the `v_webhook_endpoints` and `v_webhook_deliveries` tables.
