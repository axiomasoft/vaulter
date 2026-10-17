# Vaulter · Webhooks

`axioma-studio/vaulter-webhooks` delivers Vaulter events to external HTTP subscribers: endpoint
management (REST and Artisan), Standard Webhooks signing, SSRF-guarded outbound delivery, retries with
jitter, auto-disable of failing endpoints and a delivery log.

The package is an **outbox consumer** of the Vaulter kernel (`webhooks.fanout`): the core writes every
domain event to the transactional outbox, `vaulter:relay` hands it to the consumer, which fans it out to
the subscribed endpoints. It depends on `axioma-studio/vaulter-core` and `axioma-studio/vaulter-drive`
(endpoints are scoped to drives), is not part of the `axioma-studio/vaulter` metapackage, and is **opt-in**:
it opens outbound network traffic, so the module is active only after `vaulter.modules.webhooks` is set to `true`.

## Install

```bash
composer require axioma-studio/vaulter-webhooks
php artisan migrate        # creates v_webhook_endpoints and v_webhook_deliveries, active or not
```

```php
// config/vaulter.php
'modules' => ['webhooks' => true],
```

## What it owns

| Concern | Where |
|---|---|
| Tables | `v_webhook_endpoints`, `v_webhook_deliveries` (migrations `0001_01_01_000401` and `…402`, run from `vendor/`, never published) |
| Config | `config/vaulter-webhooks.php`, publish tag `vaulter-webhooks-config` (timeouts, retry schedule, retention, URL guard, models, schedule) |
| Commands | `vaulter:webhooks:retry`, `vaulter:webhooks:prune`, `vaulter:webhooks:redeliver` |
| Routes | the endpoint admin under the Vaulter prefix, gated by `vaulter-webhooks.manage_ability` and the `vaulter.module:webhooks` middleware |
| Outbox consumer | `webhooks.fanout` (`Vaulter\Webhooks\WebhookFanoutConsumer`) |
| Doctor | `core.webhooks.deliveries` |
| Test kit | `Vaulter\Webhooks\Testing\*` (the package's own `TestCase` builds on it; no `../core/tests`) |

The schema is migrated whether the module is active or not, and switching it off never deletes data. To remove
the module and its tables use `php artisan vaulter:module:uninstall webhooks` (a dry run by default; `--force`
rolls the migrations back once the tables are empty, `--purge` deletes the rows first).

## Development in the monorepo

The package lives in `packages/webhooks` and is split to the `axioma-studio/vaulter-webhooks` repository by
`.github/workflows/monorepo-split.yml` (listed in `release/packages.json`; the split repository must exist before
the first release). Its tests run on its own `TestCase`:

```bash
scripts/t sqlite -p webhooks
scripts/release/consumer-check.sh webhooks   # builds it in an empty directory with only core, media and drive
```

The CI job `consumer-isolation` (core, media, drive, documents, webhooks) runs the second command and is blocking:
a package that imports a sibling it does not require fails there instead of passing on the monorepo's shared
`vendor/`. See [ADR-0030](../../docs/adr/0030-package-ownership-and-schema.md).
