# ADR-0028 — Extension slots and the operation pipeline

**Status:** accepted — builds on [ADR-0027](/adr/0027-kernel-boundaries-and-modules)

## Context

[ADR-0027](/adr/0027-kernel-boundaries-and-modules) gives every package a manifest that declares
capabilities, slots and contributions. What a contribution may do depends on the kind of its slot, and
until now the stages every write needs — admission, the transaction, the events, the after-commit
callbacks — were spread over the Operations and Actions of each package: each `*Operations` method
authorized before an Action that opened its own transaction. A slot could therefore not guarantee that
a contribution runs inside the context, the transaction and the authorization of the operation, and
idempotency (D25) had no single place to claim a key in the same transaction as the mutation.

## Decision

**1. Slot kinds (D8).** The module that defines a slot fixes its kind; the consumer of the slot enforces
it. A slot ships only with a built-in production consumer, a built-in contribution and a contract test.

| Kind | What a contribution may do | Enforced by |
|---|---|---|
| Guard | veto by throwing; any exception denies (fail-closed) | the consumer |
| Restriction | only narrow the constraints it is given | the consumer checks the subset |
| Contributor | add named fragments in one batch | the collector rejects reserved and duplicate keys |
| Handler | serve one key; an unknown key is the owner's exception | the consumer picks by key |
| EventSink / OutboxConsumer | receive events in the transaction / consume the outbox after commit | the event pipeline |
| OperationMiddleware | wrap a write and call `$next` exactly once | `OperationRunner` |

A contribution that breaks its slot's contract fails with `InvalidExtensionResult` — the problem code
`extension_failed` (500), never retried and never cached as an idempotency receipt.

**2. One pipeline for every write.** `Vaulter\Operations\OperationRunner::write(OperationSpec, OperationContext, Closure $attempt)`
is the only way a write opens its root transaction. The stages are a contract and no contribution removes
one:

1. admission — the module graph is valid (`module_graph_invalid` otherwise), the operation's module is
   ready (`module_not_ready`, 503: inactive or its tables missing), a system actor carries a reason;
2. the OperationMiddleware chain in contribution order (priority, then id); each middleware calls
   `$next` exactly once with the operation it was given and returns exactly (`===`) what `$next`
   returned, otherwise `extension_failed`; throwing before `$next` vetoes the write without a mutation;
3. the root transaction: a serialization failure or a deadlock repeats the *whole* attempt (bounded,
   three attempts, then `concurrent_update`); a write inside a write (a composition) runs in the same
   transaction without a claim, a receipt or after-commit callbacks of its own and is never retried on
   its own;
4. the idempotency claim inside that transaction (D25; until operations accept keys, a key is refused
   before the transaction);
5–8. the owner's attempt: load and lock, the current authorization, validators and guards, the
   mutation with its events (`EventRecorder` requires the transaction), the snapshot built from the
   locked state;
9. commit, then the after-commit callbacks, then the result already built — never re-read. A callback that fails after the commit is rethrown as it is and never repeats the committed attempt, even when the failure is a deadlock.

Content bytes and network calls happen before `write()` or after commit: a repeated attempt repeats
only database work.

**3. A neutral context.** `OperationContext` carries the actor, a tagged tenant (`none` or an id), a
scope reference (`drive` + id), the correlation and causation ids, the idempotency key and the system
reason — no domain model. Drive paths receive a `DriveContext` (the context plus the bound drive,
owned by drive). `OperationContext::telemetry()` is the source of span attributes: no personal data,
never the idempotency key.

**4. Readiness is lazy.** `ModuleReadiness` reads nothing at boot. The first write of a process checks
the tables of the kernel and of the operation's module in one catalog query; a ready module is
remembered for the life of the process, a module found not ready is checked again on its next write.

**5. Telemetry is a contribution.** The kernel contributes `core.telemetry`
(`Vaulter\Observability\TelemetryMiddleware`) to its own OperationMiddleware slot: one
`vaulter.operation` span per write and the `vaulter_operation_duration_seconds` /
`vaulter_operations_total{operation,outcome}` metrics (`ok`, `rejected`, `error`).

## Consequences

- Every mutating public operation runs its body through the runner (P3.10); the arch test
  `OperationsUseRunnerTest` keeps it so. Reads do not use the runner.
- Authorization moves inside the attempt, so it sees the same transaction as the mutation.
- Actions keep their own `VaulterDatabase::transaction()`; inside the runner it is a savepoint and is
  never retried at that level.
- A module may observe, measure or veto every write through the middleware slot, but cannot skip the
  transaction, the authorization, the events or replace the result.

## Alternatives rejected

- **Laravel pipeline or events around Actions** — no exactly-once guarantee for `$next`, and the
  result could be replaced silently.
- **A transaction per Action, retried by Laravel** — a nested level cannot be retried honestly, and a
  composition would commit in parts.

## References

- [ADR-0027](/adr/0027-kernel-boundaries-and-modules) — modules, capabilities and slots
- [Operation pipeline](/concepts/operations)
- [Errors: `extension_failed`, `module_not_ready`](/guides/errors#extension-failed)
