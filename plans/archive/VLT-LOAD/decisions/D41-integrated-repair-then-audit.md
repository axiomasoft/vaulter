---
id: D41
date: 2026-09-20
status: accepted
item: P3
items: [P3, P4, P5, P6, P7, P8.4, P8.5, P8.6, P8.7, P8.8, P8.9, P8.10, P8.11]
supersedes: [D34, D40]
superseded_by: null
---
# D41 — Единый repair VLT-LOAD, затем один полный аудит

**Actor:** owner/Dmitry Vostrikov

**Evidence:** RAG:— явная owner directive 2026-09-20 для интегрированного repair-pass VLT-LOAD.

## Решение

1. Текущая `task:plan-design VLT-LOAD` выполняет один интегрированный repair всего оставшегося
   scope P3–P7 и P8.8; отдельного будущего design-pass или design-сессии для P8.8 не будет.
2. Mixed scope бывшего P8.8 разделяется сейчас на атомарные execution items: P8.8 выбирает targets и
   выпускает digest-bound readiness; P8.9 реализует optional tracing; P8.10 реализует только заранее
   условленные deferred metrics; P8.11 доказывает no-op/propagation/overhead. Пустой target set или
   отсутствие условий для обеих deferred metrics являются допустимым deterministic no-op producer
   result, а не основанием придумать instrumentation после просмотра результатов.
3. Исторические terminal rows P8.4/P8.5/P8.6 не принимаются как текущий digest-bound GREEN без
   штатной revalidation. P8.7 implementation/fixture evidence сохраняется, но не закрывает live
   gate; P8.7 становится единственным operator-owned continuation для live scrape, metric matrix,
   `perf-assert` и with/without overhead.
4. После успешного repair единственный следующий lifecycle step — полный
   `task:plan-audit VLT-LOAD design`. `plan-exec`/`plan-run` запрещены, пока этот аудит не выпустил
   принятый GREEN carrier `findings/D41-full-plan-audit.md` без blocking findings.

## Почему

D40 правильно запретил исполнение до единого аудита, но его последовательность оставляла отдельный
design-pass P8.8 и уже разошлась с фактом: P3–P7 детализированы, P8.5/P8.7 lifecycle carriers не
доказывают заявленный gate, а routing всё ещё ведёт по старой design-chain. Один repair устраняет
противоречия до аудита и не открывает цикл design → audit → design без нового конкретного дефекта.

## Consequences

- D34/D40 остаются историческими carriers, но их remaining routing замещён D41.
- P3–P7 потребляют P8.7 live terminal evidence; P5/P6 дополнительно потребляют reconciled P8.5.
- P7 требует завершённые P5/P6 measurement reports; отсутствие этих predecessors больше не
  разрешается как limitation.
- Первый post-audit runnable scope — reconciliation P8.4–P8.6; затем manual P8.7 live gate и только
  потом P3. P8.8–P8.11 остаются после P3–P7.
