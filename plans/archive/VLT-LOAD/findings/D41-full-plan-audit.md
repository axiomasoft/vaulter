# VLT-LOAD D41 — полный design audit

**РЕЖИМ:** независимый read-only design audit  
**ВХОД:** `task:plan-audit VLT-LOAD design`  
**СКОУП:** актуальный D41 candidate: master contract, routing/lifecycle, P3–P7, P8.4–P8.11 и `observability-spec.md`  
**НЕ ТРОГАТЬ:** product code, item implementation, соседние планы и несвязанные изменения

**Вердикт: GREEN (accepted)**  
**Дата:** 2026-09-20  
**Аудитор:** `gpt-5.6-sol/frontier/xhigh`  
**Candidate fingerprint:** `59a9c53fb7991417417749b449234e09b782e81cdf469c245a93116896be8570`

D41 repair и bounded follow-up закрыли весь доказанный changed-risk delta. P3–P7 имеют конкретные
evidence contracts; P8.4–P8.7 корректно возвращены к digest-bound reconciliation/live gate; P8.8
больше не пишет собственный input; readiness edge адресован только P8.9, а P8.10/P8.11 потребляют
обычный manifest digest через terminal evidence; target reducer и обе tracing branches замкнуты до
исполнения. Generated views свежие, `plan-lint` проходит с 0 errors. Blocking findings отсутствуют;
carrier принят GREEN и открывает только объявленный D41 successor P8.4–P8.6.

## Проверки

- Route evidence: provider thread state, `gpt-5.6-sol/frontier/xhigh`, точный cwd и invocation.
- `plan-lint plans/VLT-LOAD`: **0 errors, 15 warnings**; предупреждения относятся к legacy journal/
  portability и уже объявленному phase-first состоянию.
- `plan-views decisions --check` и `plan-views status --check`: GREEN.
- Локальная трассировка item Inputs/Files/Deliverables в Task `evidence-graph/v1` и
  `producer-readiness/v1`; внешние факты для находок не требовались.

## Проверка исправленных находок

### F1 — RESOLVED: self-invalidating evidence anchor P8.8

**Status:** RESOLVED  
**Evidence ID:** `P8.8:SELF-INVALIDATING-OBSERVABILITY-SPEC`

`P8.8` одновременно объявляет `observability-spec.md` digest-input, разрешённый `Files` и
deliverable с обновлением selected-targets section. Terminal readiness затем должен быть связан с
digest этого же spec. При обязательной fresh closure это даёт реальное `Files ∩ digest_inputs` и
штатный `SELF_INVALIDATING_EVIDENCE_ANCHOR`: product write инвалидирует bundle, которым он был
допущен.

**Repair verified:** §20 оставлен frozen input, динамический выбор хранится только в
`artifacts/P8/tracing-targets.json` + finding, либо разделить selection и последующую projection/
closure на разные items. `observability-spec.md` не должен быть одновременно входом и изменяемым
выходом P8.8.

### F2 — RESOLVED: readiness consumer mismatch

**Status:** RESOLVED  
**Evidence ID:** `P8.8:READINESS-CONSUMER-MISMATCH`

Deliverables P8.8 объявляют единственного `consumer=P8.9`. Закрытая схема
`producer-readiness/v1` содержит ровно один `consumer_item`. Однако Inputs/Implementation Rules
P8.10 требуют непосредственно P8.8 readiness и разрешают product mutation по его flags. Проекция с
`consumer_item=P8.9` не может допустить P8.10; фраза о транзитивном digest не создаёт carrier edge,
а P8.10 не зависит от `P8.9.state.md`.

**Repair verified:** P8.10 больше не потребляет foreign readiness; exact manifest digest записывается
в P8.8 terminal state и сверяется через terminal evidence P8.9/P8.10.

### F3 — RESOLVED: target-selection reducer детерминирован

**Status:** RESOLVED  
**Evidence ID:** `P8.8:TARGET-RANKING-UNDERSPECIFIED`

P8.8 обещает deterministic reducer и максимум шесть targets, но последний ranking key задан как
«total observed time/wait» сразу по пяти разнородным reports. Не определены authoritative row per
target, агрегация повторов/нескольких producer reports, единицы/window normalization, обработка
missing values и полный tie-break. Два корректных исполнителя могут выбрать разные target sets, а
значит разные package Files и public surface.

**Repair verified:** §20.2 фиксирует closed score tuple, authoritative-row reduction, integer
`impact_ppm`, missing-value policy, evidence-id и target-id tie-break; cross-report tie fixture
обязателен до live values.

### F4 — RESOLVED: public TraceRecorder и empty-target branch замкнуты

**Status:** RESOLVED  
**Evidence IDs:** `P8.9:PUBLIC-TRACE-API-UNFROZEN`, `P8.11:EMPTY-TARGET-BRANCH-AMBIGUOUS`

§20 называет `TraceRecorder` публичным SDK-neutral API, но не фиксирует PHP-сигнатуру callback API,
порядок аргументов/attributes и result/exception typing. Одновременно empty P8.8 заставляет P8.9 не
создавать вообще никаких tracing symbols/source diff, тогда как P8.11 безусловно объявляет
`OpenTelemetryTraceRecorder` и adapter/provider proof. В empty branch отсутствует package contract,
который host adapter мог бы реализовать; Files/Validation не говорят, какие из них запрещены.

**Repair verified:** public signature frozen как
`span(string $name, callable $operation, array $attributes = []): mixed`; nonempty branch владеет
adapter/SDK/propagation proof, empty branch запрещает tracing symbols/adapter/SDK/config и доказывает
нулевой diff/export.

## Неблокирующие наблюдения

- `bundles/P3.1.json` уже материализован, хотя P3 context говорит, что bundle до GREEN P8.7 не
  создаётся. Handoff правильно считает его non-dispatchable snapshot; owning repair должен оставить
  одно правило и регенерировать bundle только после готовности producer.
- Design repair выполнен текущим `frontier/xhigh`, что является безопасным overshoot относительно
  `frontier/high`; финальный audit также выполнен на canonical `xhigh`.

## Audit result

**Owning continuation:** `exec-items: task:plan-exec VLT-LOAD P8.4`  
**Decision:** `audit-green`  
**Gate:** D41 audit gate принят; разрешён только первый roadmap scope P8.4–P8.6 с byte-fresh bundles
и обычными item validation/write-site checks.
