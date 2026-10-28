---
title: Outbox dead letters
---

# Outbox dead letters

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A consumer has dead deliveries, a growing due backlog or a recorded gap. An outbox delivery is distinct from a webhook endpoint delivery.

## Read-only diagnosis

Start with the existing checks:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=core.outbox
```
Inspect `core.outbox.dead`, `core.outbox.lag`, `core.outbox.consumer_gaps` and `core.outbox.leases`. From read-only delivery/event records capture consumer id, state, attempt count and the smallest affected event-sequence interval. Confirm that events remain inside retention and fix the consumer failure first. Doctor counts/gaps do not prove a receiver applied an event. Endpoint delivery failures use the [webhook runbook](./webhook-delivery-failures-and-redelivery).

## Bounded action

Replay mutates delivery state and can repeat external effects. Obtain explicit owner authorization, a verified database backup and receiver idempotency confirmation. Replace `CONSUMER_ID`, `FROM_SEQ` and `TO_SEQ` with validated values from diagnosis (positive numeric seqs, from ≤ to). Approve a small interval first; these are documentation placeholders, not runnable production values.
<!-- snippet: shell -->

```bash
php artisan vaulter:relay:replay --consumer=CONSUMER_ID --from=FROM_SEQ --to=TO_SEQ
php artisan vaulter:relay --once --consumer=CONSUMER_ID --limit=100
```
Replay returns retained done/dead rows to pending with a fresh attempt budget and acknowledges covered gaps. `--ack-only` acknowledges loss without replaying or delivering anything; it is not dead-letter recovery. Missing retained events cause failure and require a separate owner decision about the loss. The relay limit bounds deliveries per consumer/pass; registration and holds still cover all consumers.

## Postcondition

Record the replay covered range and dead-to-pending count, then inspect those exact deliveries and consumer-side effects. Repeat outbox diagnosis and confirm no unexplained gap or recurring dead state. A successful relay exit reports a pass, not proof that every receiver succeeded; pending retries must be tracked to completion.

## Rollback

Stop further relay scheduling through the host procedure if effects are wrong. There is no undo-delivery command: compensate external effects with the consumer owner. Do not restore an old database under active consumers, as that may repeat deliveries. Preserve evidence and the backup; acknowledge unrecoverable loss only through a separate explicit owner decision.
