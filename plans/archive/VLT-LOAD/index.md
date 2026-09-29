> **Архивировано 2026-09-29:** исходные P0–P8 терминальны (Done with deviations). Их не запускать повторно. Сохранившиеся proof gaps и последующие результаты — в [REMAINING-WORK](../../REMAINING-WORK/REMAINING-WORK.md); product GREEN не заявлен.

# Индекс плана — VLT-LOAD

**Обновлён:** 2026-09-23

## Карта директорий

| Путь | Назначение |
|:--|:--|
| `plan.md` | stable scope, rules, routing and lifecycle decisions |
| `status.md` | generated status projection |
| `roadmap.md` | historical execution graph; current routing is in ../REMAINING-WORK.md (entrypoints: ../ACTIVE.md and ../RUNBOOK.md) |
| `phases/P0/`…`phases/P8/` | phase contexts, atomic item specs and generated item states |
| `decisions/` | append-only decisions plus generated current/archive/index views |
| `findings/` | audit, RAG and evidence interpretation carriers |
| `artifacts/` | machine-readable execution evidence; created only by owning items |
| `bundles/` | historical digest-qualified executor inputs; not current launch authority |
| `brief/` | durable owner inputs and repair scope |
| `root/contracts/` | long-lived lifecycle/evidence schemas inherited from migration |
| `handoff.md` | terminal plan handoff; no runnable Pn.m |
| `journal.jsonl` | append-only lifecycle events |
| `open-questions.md` | unresolved questions only |

## Провенанс

| Источник | Слой | Питает | Суть |
|:--|:--|:--|:--|
| `brief/2026-09-20-integrated-repair.md` | brief | D41, P3–P8.11, routing | owner-authorized integrated repair and one-audit boundary |
| `brief/2026-09-23-l04-core-retry.md` | brief | separate L-04 dev slice | bounded core retry claim after terminal P6 evidence |
| `findings/P8.7-gate-closure.md` | findings | P8.7, P3–P7 | live operator gate closure (GREEN 2026-09-21) |
| `findings/P3-rag-postgres-profiling.md` | findings | P3 | PostgreSQL profiling method and claim boundaries |
| `findings/P4-rag-memory-streaming.md` | findings | P4 | allocator/process/cgroup measurement model |
| `findings/P5-rag-postgres-concurrency.md` | findings | P5 | lock/PgBouncer/concurrency evidence model |
| `findings/P6-rag-laravel-queue.md` | findings | P6 | queue/outbox/webhook semantics |
| `findings/P7-rag-chaos-recovery.md` | findings | P7 | fault fidelity and recovery evidence |
| `findings/P8-rag-otel-health.md` | findings | P8 | optional SDK/health/tracing boundaries |

## Ключевые артефакты `root/`

| Артефакт | Суть | Статус |
|:--|:--|:--|
| `root/contracts/read-budget-v1.md` | bounded execution-read admission contract | sealed v1 |
