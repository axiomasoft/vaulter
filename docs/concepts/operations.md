---
title: Operation pipeline
description: How every Vaulter write runs — the neutral operation context, OperationRunner stages, operation middleware, module readiness, retries and telemetry.
outline: deep
---

# Operation pipeline

Every write in Vaulter — from REST, Filament, host PHP code, a job or a command — goes through the
owning module's public operation and then through one kernel component, `OperationRunner`
([ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)). Reads do not use it.

## The context

An operation runs with an `OperationContext`: who acts (`Actor` — a user or the system with a
reason), for which tenant (`TenantRef`: `none` or an id), within which scope (`ScopeRef`, for example
`drive` + id), under which correlation and causation ids, and with which idempotency key. It holds no
domain model. Drive operations receive a `DriveContext` — the same context plus the bound drive.

`OperationContext::telemetry()` gives the attributes of spans and logs: correlation and causation
ids, the scope kind, the tenant and actor *kinds*. It never contains user ids, tenant ids or the
idempotency key.

## The stages

| # | Stage | What can stop it |
|---|---|---|
| 1 | Admission: a valid module graph, a ready module, a system actor with a reason | `module_graph_invalid`, `module_not_ready` |
| 2 | Operation middleware, in contribution order | a veto (an exception before `$next`), `extension_failed` |
| 3 | The root transaction; a deadlock or serialization failure repeats the whole attempt | `concurrent_update` after three attempts |
| 4 | The idempotency claim, inside the transaction | a key on an operation that does not accept keys yet |
| 5–8 | The owner's attempt: lock, authorize, validate, mutate and record events, build the snapshot | the owner's problems (`permission_denied`, `stale_write`, …) |
| 9 | Commit, after-commit callbacks, the result already built | — |

A write started inside another write (a composition) joins the outer transaction: one commit, one set
of after-commit callbacks, no retry of its own. If the outer operation fails, the nested one is rolled
back with it.

Uploaded bytes and network calls are never inside a repeated attempt: content is staged before the
write and jobs are dispatched after commit.

## Operation middleware

A module can contribute to the `Vaulter\Operations\OperationMiddleware` slot to observe, measure or
veto every write. A middleware must call `$next` exactly once, with the operation it received, and
return exactly what `$next` returned. Anything else — not calling it, calling it twice, passing another
operation, returning another value or swallowing the operation's exception — fails the write with
`extension_failed`. The kernel's own `core.telemetry` middleware emits the `vaulter.operation` span and
the `vaulter_operation_duration_seconds` and `vaulter_operations_total{operation,outcome}` metrics.

## Readiness

Booting the application queries nothing. The first write of a process checks, in one catalog query,
that the operation's module is active and that its tables and the kernel's exist; a ready module is
remembered for the rest of the process. A missing table answers `module_not_ready` (503) before any
transaction opens — run `php artisan migrate` and retry.

## See also

- [Modules](/concepts/modules)
- [Errors](/guides/errors#module-not-ready)
- [ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)
