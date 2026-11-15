---
title: Webhooks
---

# Webhooks

## Install and switch on

Webhooks are the separate package `axioma-studio/vaulter-webhooks`. It requires `vaulter-core` and
`vaulter-drive` (endpoints are scoped to drives) and is not part of the `axioma-studio/vaulter` metapackage,
because it opens outbound network traffic:

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter-webhooks
```

The module is **opt-in**: installing the package creates its tables (`v_webhook_endpoints`,
`v_webhook_deliveries`) with `php artisan migrate`, but nothing is mounted until you switch it on:

<!-- snippet: host -->

```php
// config/vaulter.php
'modules' => ['webhooks' => true],
```

Its settings live in `config/vaulter-webhooks.php` (publish tag `vaulter-webhooks-config`; reference:
[Configuration](/reference/config)). Removing the module later is `php artisan vaulter:module:uninstall webhooks`
([Modules](/concepts/modules#uninstalling-a-module)).

## How an event reaches the outbox

Every Vaulter change records its event through the `EventRecorder` as the last step of
its own database transaction (see [Events](/reference/events)). The recorder writes one
`v_outbox` row for the occurrence **in that transaction**, whatever modules are active
(see [The outbox](/concepts/events-outbox)); with the opt-in webhooks module active
(`vaulter.modules.webhooks` = `true`, off by default) it also writes the pending delivery
row of the `webhooks.fanout` outbox consumer for every external event type:

- the change and its outbox rows commit together, so a committed change is never
  missing its webhook, even if the process dies right after the commit;
- if the outbox row cannot be written, the change is rolled back and the operation
  fails — Vaulter does not commit a change it cannot announce;
- your Laravel listeners run only after the commit; a failing listener is reported and
  never undoes the change.

The core relay, `vaulter:relay`, then hands each event to `webhooks.fanout`, which fans it
out into webhook deliveries.

## Delivery guarantees

The `webhooks.fanout` consumer turns each external event into one `v_webhook_deliveries`
row per eligible endpoint and queues a job per delivery:

- **Every eligible endpoint gets exactly one delivery row per event.** The relay leases
  the event's outbox delivery (`outbox.lease_seconds`, default 60); the consumer writes
  `vaulter-webhooks.relay.fanout_chunk` endpoints (default 100) per transaction and saves the last
  one as the delivery's continuation, fenced by the lease. The outbox delivery is done only
  after the last chunk. If the relay dies, the next run takes the delivery over once the
  lease lapses and continues after the continuation; a worker that lost its lease writes
  nothing, and `UNIQUE(event_id, endpoint_id)` backs this up. You may run several relays
  at once.
- **Jobs are queued after the rows commit.** A job lost after that is re-queued by the
  retry sweep (`vaulter:webhooks:retry`) once the delivery has waited
  `vaulter-webhooks.retry_claim_seconds` without being picked up.
- **HTTP is at-least-once.** A delivery is retried on failure and can arrive twice if
  your acknowledgement is lost: deduplicate on the envelope `id`. Deliveries are not
  ordered.
- **A switched-off module loses nothing silently.** While `vaulter.modules.webhooks` is
  off, `webhooks.fanout` is paused: its pending deliveries wait and it holds the outbox
  retention for up to `outbox.max_consumer_hold_seconds`. After that it is detached with an
  explicit gap that `vaulter:doctor` reports until `vaulter:relay:replay` replays or
  acknowledges it.
- **Prune deletes only finished work.** `vaulter:webhooks:prune` removes
  `delivered`/`dead` deliveries older than `vaulter-webhooks.retention_days`; a `pending`/`failed`
  delivery stays however old it is. The outbox has its own retention,
  `vaulter:outbox:prune`. Pruned rows are gone for good — nothing can replay them.

## Who receives an event

An endpoint receives an event when, at the moment its chunk is written:

- it is active and subscribed to the event type;
- its tenant equals the event's tenant — an endpoint without a tenant receives only
  events without one;
- it has no `drive_id`, or the event happened in that drive;
- it already existed when the event happened (its `created_at` is not later than the
  event's). An endpoint registered afterwards never receives older events.

An endpoint deactivated while an event is being fanned out misses the remaining part
of it. Register and update endpoints in the tenant they belong to: under strict tenancy
the endpoint takes the current tenant, and `drive_id` must name a drive of that tenant
(any other id answers 404).

Management uses that same exact tenant boundary. A manager of tenant A cannot list, read,
update, delete or rotate the secret of an endpoint whose `tenant_id` is null. Updating an
endpoint preserves its tenant; it cannot adopt a legacy endpoint. Assign legacy endpoints
through an explicit administrative data migration before tenant-scoped management.

## The envelope

The outbox payload is the webhook body. It is built once per occurrence and copied
into every delivery:

```json
{
  "id": "01JA2Z4P6V8Y0C1D2E3F4G5H6J",
  "type": "node.renamed",
  "version": 1,
  "source": "vaulter",
  "time": "2026-10-09T12:00:00.123Z",
  "tenant_id": null,
  "drive_id": "42",
  "subject": {"type": "node", "id": "1337"},
  "sequence": 7,
  "actor": {"type": "App\\Models\\User", "id": "5"},
  "correlation_id": "01JA2Z4P6V8Y0C1D2E3F4G5H6K",
  "changes": {"seq": 128},
  "data": {}
}
```

- `id` identifies the occurrence: it is the activity row's `event_id` and stays the
  same on every retry of every delivery. **Deduplicate on `id`** — delivery is
  at-least-once.
- `data` is thin: ids, enums, counters and flags. Names, paths, mime types, tokens and
  request metadata are never sent; read the subject with your own credentials when you
  need them. The per-type keys are listed in the [catalogue](/reference/events#catalogue).
- `sequence` is the node's sequence after the change (`null` for events that do not
  change a node, and for document content, which carries `state_version` and `epoch`
  in `data`). Deliveries are not ordered; compare `sequence` (or `state_version`) to
  drop a stale update.
- `version` is the schema version of `data` for this `type`.
- `changes.seq` is the position of this occurrence in the drive's
  [change journal](/concepts/events) (`null` for an event that is not journalled, or when
  `vaulter-drive.features.changes` is off). It is a hint, not a cursor: a client that syncs a drive treats the
  webhook as a signal to call `changes()->list()` with its stored cursor, and may skip the call
  when its cursor is already past `seq`.

## Headers and signature

Every delivery is a `POST` of the envelope with exactly these headers, as defined by
[Standard Webhooks](https://www.standardwebhooks.com/):

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `Vaulter-Webhooks/1` |
| `webhook-id` | the envelope `id` — identical on every retry and redelivery |
| `webhook-timestamp` | Unix seconds of this attempt |
| `webhook-signature` | `v1,<base64>` — several signatures separated by a space while a secret is being rotated |

The signature is `base64(HMAC-SHA256(key, "{webhook-id}.{webhook-timestamp}.{body}"))` over
the exact bytes sent. The secret is `whsec_` followed by the base64 of the key; the key is
the decoded part. It is shown once, when the endpoint is registered or rotated. The event
type is the `type` field of the body (there is no event header).

## Verifying a delivery

Any Standard Webhooks library verifies Vaulter deliveries — see the
[official libraries](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries).
In PHP, `Vaulter\Webhooks\StandardWebhookVerifier` does it without extra dependencies:

<!-- snippet: host -->

```php
use Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException;
use Vaulter\Webhooks\StandardWebhookVerifier;

// $request->getContent() is the RAW body: verify it before decoding or re-encoding.
try {
    (new StandardWebhookVerifier)->verify(
        headers: $request->headers->all(),
        body: $request->getContent(),
        secrets: [config('services.vaulter.webhook_secret')], // whsec_… (add the old one during a rotation)
        toleranceSeconds: 300,
    );
} catch (InvalidWebhookSignatureException) {
    abort(400);
}
```

The check needs all three headers, a `webhook-timestamp` within the tolerance (300 s by
default, in both directions — this is what rejects a replay), and a `v1` signature that
matches one of the secrets (compared in constant time). Reply with a `2xx` within the
timeout; anything else — a redirect included — counts as a failed attempt.

## Rotating a secret

`RotateWebhookSecretAction::execute($endpoint, ?int $overlapSeconds = null)` returns a
`RotatedWebhookSecret` (`endpointId`, `secret`) — the new secret, once. The old secret keeps
signing for `vaulter-webhooks.secret_rotation_overlap` seconds (default 86400, at most 604800), so
`webhook-signature` carries two signatures and a receiver holding either secret verifies the
delivery. Deploy the new secret to the receiver during that window. An overlap of `0` drops the
old secret at once — use it when a secret leaked.

## Retries and automatic disabling

A failed attempt (non-2xx, redirect, timeout, connection error) is retried after
`vaulter-webhooks.retry_schedule` seconds (default `5, 300, 1800, 7200, 18000, 36000, 50400, 72000,
86400`, each ±`vaulter-webhooks.retry_jitter`, default 10 %). That is ten attempts over about three
days; the failure after the last one makes the delivery `dead`. The HTTP timeout is
`vaulter-webhooks.timeout` (default 15 s).

The first failed attempt of an endpoint starts a *failing run* (`failing_since`); any
successful delivery ends it. When the run is `vaulter-webhooks.disable_after_days` old (default 5; `0`
never disables) the endpoint is switched off (`is_active = false`, `disabled_reason = 'failing'`)
and its pending and failed deliveries become `dead` with `last_error = 'endpoint_disabled'`.
After the commit Laravel dispatches `Vaulter\Webhooks\Events\WebhookEndpointDisabled`
(`endpointId`, `reason`, `failingSince`) — listen to it to tell the endpoint's owner. Switching
an endpoint off by hand (`disabled_reason = 'manual'`) kills its open deliveries the same way;
switching it on clears the run and the reason.

## Redelivery

`RedeliverWebhookAction::execute($delivery)` sends a `delivered` or `dead` delivery again: the
same row goes back to `pending` with `attempts = 0`, so the receiver sees the **same
`webhook-id`**. Its endpoint must be active, and a delivery that is still `pending` or `failed`
is refused — `WebhookDeliveryNotRedeliverableException`
([`webhook_delivery_not_redeliverable`](/guides/errors#webhook-delivery-not-redeliverable), 409).
In bulk: [`vaulter:webhooks:redeliver`](/reference/commands#vaulter-webhooks-redeliver).
Only deliveries still inside `vaulter-webhooks.retention_days` can be redelivered.

## Managing endpoints over HTTP

The admin routes of the [REST API v1](/reference/rest-api#webhook-endpoints) are mounted when
`vaulter.modules.webhooks` is on. They are a global surface, not a drive resource: every route —
reads included — is authorized by the `WebhookEndpointPolicy` against
`vaulter-webhooks.manage_ability` before the id is even looked up, so a caller who may not manage
webhooks learns nothing about which endpoints exist.

| Route | What it does |
|---|---|
| `GET v1/webhook-endpoints` | endpoints, newest first, as a cursor page |
| `POST v1/webhook-endpoints` | register (`url`, `events`, `is_active`, `drive_id`) — the answer carries `secret`, once |
| `GET v1/webhook-endpoints/{endpoint}` | one endpoint |
| `PATCH v1/webhook-endpoints/{endpoint}` | change `url`, `events`, `is_active`, `drive_id` |
| `POST v1/webhook-endpoints/{endpoint}/deactivate` | switch off; open deliveries become `dead` |
| `POST v1/webhook-endpoints/{endpoint}/rotate-secret` | new secret, once; `overlap_seconds` keeps the old one valid meanwhile |
| `DELETE v1/webhook-endpoints/{endpoint}` | delete |
| `GET v1/webhook-endpoints/{endpoint}/deliveries` | its deliveries, newest first, as a cursor page |
| `POST v1/webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver` | `202`, see [Redelivery](#redelivery) |

An endpoint is `{id, url, drive_id, events, status, disabled_at, created_at}` with `status`
`active` or `disabled`; the stored secret is never read back. A delivery is
`{id, event_id, event_type, status, attempts, next_retry_at, last_status_code, created_at}`,
where `last_status_code` is the HTTP status of the last failed attempt when the endpoint
answered at all; payloads and raw error texts stay on the server. Unknown ids answer
`404 webhook_endpoint_not_found` or `404 webhook_delivery_not_found` (a delivery of another
endpoint is unknown too).

## Deduplication and ordering

Delivery is at-least-once and unordered: deduplicate on `webhook-id` (the envelope `id`), and
use `sequence` to drop a stale update. A client that needs every change of a drive, in order,
reads the [change journal](/concepts/events) and uses webhooks only to wake up.

## Event names

The event catalogue (`DriveEventType`, `DocumentEventType`) uses the canonical 0.3 names. Upgrade migration
№9 converts `v_activity.verb`, `v_outbox.event`, `v_webhook_deliveries.event` and
`v_webhook_endpoints.events` together. Endpoint lists are processed in chunks of
500; list order, duplicates, `*` and unrecognized third-party names are preserved.

| Old name | Canonical name |
|---|---|
| `storage.created` | `drive.created` |
| `node.created` | `folder.created` |
| `version.created` | `file.revision_added` |
| `node.force_deleted` | `node.purged` |
| `upload_session.initiated` | `upload.initiated` |
| `upload_session.aborted` | `upload.aborted` |
| `upload_session.expired` | `upload.expired` |

`file.uploaded`, node move/rename/copy/trash/restore, grant and share events keep
their names. Upload lifecycle events remain audit-only. Update downstream webhook
consumers when deploying the schema/code upgrade. The activity occurrence ID and
outbox aggregate fields are filled for every event recorded since the event pipeline
(the 1.0 event types are listed in the [catalogue](/reference/events#catalogue)).

The catalogue is closed: each type declares an `EventSpec` (subject, the keys and types
of `data`, which sinks record it) and the table in the [events reference](/reference/events#catalogue)
is generated from it, so the reference cannot drift from the code. The 1.0 catalogue adds
`node.updated` (properties changed), `access.inheritance_changed`, `share.updated` and
`share.rotated`; `share.updated` reports only the *names* of the changed fields in
`data.changed`, and no envelope ever carries a share token, its hash or prefix, a
password, a name, a path or request details.

The admin REST resources use the v1 shape described above.
