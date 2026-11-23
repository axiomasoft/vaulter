---
title: The outbox
---

# The outbox

Every domain event Vaulter records is written to the transactional outbox, `v_outbox`, in
the transaction of the operation that caused it — whatever modules are active. Outbox
consumers receive the events after the commit, at least once, each under its own
delivery rows. The webhooks module contributes `webhooks.fanout`. Modules can contribute their own
consumers through the `OutboxConsumer` slot. No event is lost silently: for a
durable consumer each event is delivered, dead-lettered, or part of an explicit gap.

## Tables

| Table | One row per | Holds |
|---|---|---|
| `v_outbox` | event | `outbox_seq` (a monotonic order, independent of the id strategy), `event_id` (unique), tenant, `scope_kind`/`scope_id` (a drive, or the tenant), aggregate and its `sequence`, `type`, `external`, the payload |
| `v_outbox_consumers` | consumer | delivery class (`durable`, `ephemeral`), state (`active`, `paused`, `detached`), `activation_seq`, `reconciled_through_seq`, the reconciler lease, the gaps |
| `v_outbox_deliveries` | event × consumer | state (`pending`, `leased`, `done`, `dead`), attempts, `next_attempt_at`, the delivery lease and its generation, the consumer's continuation, `last_error` |

## Writing

The `EventRecorder` writes the event last, after the event sinks (the drive's activity
feed and change journal), so the payload carries the journal seq. In the same
transaction it writes a `pending` delivery row for each consumer of the module graph
that subscribes to the type. A rolled-back operation leaves neither; a replayed
occurrence (the same `event_id`) writes nothing. Without consumers only the event is
written, and retention removes it.

`created_at` retains the occurrence time used by envelopes, subscription eligibility and retention.
After allocating `outbox_seq`, the writer records microsecond-precision UTC `staged_at` in the same
transaction. A failure to record it rolls the event back too; a committed production event is staged.

## The relay

`vaulter:relay` runs every minute (`vaulter.schedule.outbox_relay`), without overlapping.
One pass:

1. **Register.** Every consumer of the module graph gets its row: a new one starts at the
   current seq, a paused one resumes where it stopped, a detached one starts over at the
   current seq. A consumer the graph no longer holds — its module switched off — is
   paused.
2. **Reconcile.** A process that ran with an older module graph wrote events without a
   row for a consumer it did not know. Under the consumer lease, the relay inserts the
   missing rows for the events up to S, the highest seq whose `staged_at` is more than
   `outbox.settle_window_seconds` ago, and moves `reconciled_through_seq` to S. It never
   moves further: a long transaction can still commit a smaller seq inside the window.
   Keep writer and relay clocks synchronized and the window above the longest Vaulter transaction;
   `vaulter:doctor`
   (`core.outbox.settle_window`) compares it with the database's transaction limits.
3. **Deliver.** Due rows are leased (`FOR UPDATE SKIP LOCKED` on PostgreSQL and MySQL),
   the consumer runs outside any transaction, and its result is written only under the
   lease generation it was leased with: a worker whose lease expired and was taken over
   writes nothing. A retry waits a random delay up to
   min(`backoff_cap_seconds`, `backoff_base_seconds` · 2^attempts) (full jitter); after
   `outbox.max_attempts` the row is `dead`.
4. **Hold.** A durable consumer paused longer than `outbox.max_consumer_hold_seconds` is
   detached: its open rows become `dead` and the range it missed is recorded as a gap,
   counted in `vaulter_outbox_consumer_gap_events_total` and reported by `vaulter:doctor`
   as an error until it is closed. An ephemeral consumer is detached without a gap.

Close a gap with `vaulter:relay:replay --consumer= --from= --to=`: the events of the range
still in the outbox become pending again for that consumer (done and dead rows included),
and the gaps the range covers are acknowledged; it exits 1 when part of the range is no
longer in the outbox. `--ack-only` acknowledges the loss without a replay.

## Dead letters

A row that used up `outbox.max_attempts` is `dead`; it stays for `outbox.dead_retention_seconds` and does not
hold back other events or other consumers: each consumer has its own rows, so a dead row of one never delays
another. Where to see it:

- `vaulter:doctor` — `core.outbox.dead` warns with the number of dead rows per consumer within the dead retention;
  read `last_error` of the rows to find the cause.
- metrics — `vaulter_outbox_dead_total{consumer}` counts rows that died, `vaulter_outbox_dead_rows{consumer}` is the
  current number of dead rows (a gauge that falls when they are replayed or pruned).

To retry, fix the cause and run `vaulter:relay:replay --consumer= --from= --to=` for the seq range of the rows.
The dead rows of that range become `pending` again with `attempts` reset to 0, and the command prints how many.
Rows outside the range stay as they are.

## Retention

`vaulter:outbox:prune` runs hourly (`vaulter.schedule.outbox_prune`). It deletes an event
older than `outbox.retention_seconds` only when no delivery of it is open and every durable
consumer that is not detached has reconciled past it — a paused consumer holds it until it
is detached. Done rows go with their event; dead rows stay `outbox.dead_retention_seconds`.

## Ordering and duplicates

There is no order between events. The payload carries the aggregate and its `sequence`, so
a consumer can order per aggregate. Delivery is at least once: deduplicate on the event id.

## Writing a consumer

Implement `Vaulter\Events\Outbox\OutboxConsumer` and contribute it from your module's
manifest under its id (`$module->slot(OutboxConsumer::class, MyConsumer::class, 'my.consumer')`).
`subscribes()` picks the event types, `deliveryClass()` says whether the consumer holds
retention, and `consume()` returns done, retry or dead. A consumer that works in steps
saves its cursor with `DeliveryControl::saveContinuation()`, a write fenced by the lease:
when it returns false the delivery was taken over and the consumer stops. A consumer
changes the core tables only through `DeliveryControl`.
