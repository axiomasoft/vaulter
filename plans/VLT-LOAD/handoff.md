# HANDOFF — 2026-07-14 — after P1.5

**Next:** `P2` — нагрузочный харнесс (инструмент, профили трафика). Фаза P2 сегодня **скелет**
(0/1, поля не развёрнуты) — первый шаг обязан быть `/task:plan-design`, не `plan-exec` (иначе он
упадёт на предусловии протокола).

| Параметр | Значение |
|:--|:--|
| Model | **opus** (§3 Routing плана: «P2 (харнесс), P3 (профилирование БД) — opus/high + `Explore`/`general-purpose» — выбор инструмента и харнесс-архитектуры — решение, не механика) |
| Thinking | **high** — выбор инструмента (k6 + xk6-fasthttp, D2–D4 уже приняты design-фазой P0) и раскладка профилей трафика по SLO §0.1/§7 `slo-gates.md` — решения, не разведка |
| Context | **NEW SESSION — Workflow-оркестрован НЕ требуется на этом шаге** (`plan-design` для одной фазы — обычный item-by-item, `continue (/clear)` достаточно) |
| Суть | Развернуть `phases/P2.md`: харнесс k6/xk6-fasthttp, profile-раскладка (интерактив/upload/download как отдельные executor'ы под общий `pm.max_children`), фикстуры, seed-команды, docker-compose.load.yml по конверту `reference-env.md` |

```
/task:plan-design VLT-LOAD P2
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка кодовой базы, RAG-провенанс инструментов
  (`findings/P0-rag-load-tooling.md`), мастер-план (`plan.md`, D1–D11).
- **P1 🟢 Done (4/5, P1.1 — 🟠 Done with deviations)** — фаза закрыта 2026-07-14:
  - **P1.1 🟠** — `reference-env.md`: тиры S/M/L (cpuset-конверт, `pm.max_children` 8/32/96) +
    объёмный конверт `v_*`. Конфликт «S не влезает в `ubuntu-latest`» → CI-профиль **S0**
    (D17, подтверждён D21).
  - **P1.2 🟢** — `operations-catalog.md`: 47 `OP-nn` (56 маршрутов обойдено, 18 исключено).
  - **P1.3 🟢** — `slo.md`: **81 `SLO-nn`** (27 групп × S/M/L), все **`approved`**.
  - **P1.4 🟢** — `slo-gates.md`: **97 строк гейта на 81 SLO**. Класс A — блокирующий, класс B —
    advisory (D20). Две плоскости оценки — k6-thresholds + perf-assert (**D18**). P8-минимум —
    37 метрик + `/up`.
  - **P1.5 🟢** — `slo-signoff.md`: владелец (Dmitry Vostrikov) принял **все 81 строку** `slo.md`
    «золотой серединой» без правки чисел; приоритет — стабильный публичный API. Q1 (perf-раннер —
    **нет**, класс B advisory, **D20**), Q2 (поддерживаемый тир README — **M**, входит в D19),
    Q3 (CI-профиль **S0** — принят, **D21**). Roadmap-кандидаты на ужесточение — `slo-signoff.md` §3,
    не блокируют P2.

**Remaining:**
1. **P2–P8** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` по порядку
   `P2 → P8-минимум → {P3,P4,P5,P6} → P7`. P2 — следующий шаг (см. Next выше).
2. **δ = 10 %** `[гипотеза]` (baseline-регрессия класса B) — P2 обязан измерить CV (5 прогонов) и
   подтвердить/пересмотреть; переход класса B в блокирующий тоже решается в P2 по факту CV
   (`slo-gates.md` §4.4, условие 2 — условие 1, perf-раннер, снято отрицательным ответом Q1/D20).
3. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); SLO-46…48
   формально нарушены, гейт красный до фикса; срез заблокирован **D11** (VLT-MUT drive ≥ 95).
4. **Roadmap-кандидаты на ужесточение SLO** (`slo-signoff.md` §3) — не срочно, пересмотр после
   первых прогонов P2.
5. **Deferred, не блокирует P2:** `plan.md` §0 Meta `Execution Mode` и §4 Status Board всё ещё
   называют P8 «параллельной» (расхождение с D9, P8 = гейт) — правка мастер-плана, работа
   `/task:plan-design`.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §5 Decision Log (D1–D21), §4 доска, §7 Findings-долг
  (L-01…L-08).
- `plans/VLT-LOAD/reference-env.md` (тиры + S0) · `operations-catalog.md` (47 OP) ·
  `slo.md` (81 SLO, все `approved`) · `slo-gates.md` (97 гейтов, P8-минимум) ·
  **`slo-signoff.md` (sign-off владельца, roadmap-кандидаты §3)**.
- `plans/VLT-LOAD/phases/P1.md` — ТЗ пяти items + Phase Handoff (закрыт); `open-questions.md` —
  Q1–Q3 разрешены.
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md`.

**Open risks:**
- **δ = 10 % не измерен** — до P2 (5 прогонов) относительный гейт класса B не имеет доказанной
  чувствительности; владелец принял 10 % как рабочую гипотезу (D19), не как финальное число.
- **Класс B на `ubuntu-latest` остаётся advisory** — владелец подтвердил (D20): perf-раннер не
  планируется; латентная защита в CI сегодня не покрыта, только `lab`-профиль (ручной/lab-box)
  гейтит латентность по-настоящему.
- **Половина класса A вычисляется вне k6** (perf-assert/PromQL, D18) — зависит от P8-минимума:
  пока seam'ов нет, эти пороги нечем считать.
- **Числа L-долгов** (§7 `plan.md`) — экстраполяция, гейта в CI не имеют (`slo-gates.md` §6.2).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** —
- **Deferred:** roadmap-кандидаты на ужесточение (`slo-signoff.md` §3); правка `plan.md` §0/§4
  «P8 параллельна» → «P8 гейт» (работа `/task:plan-design`); пороги G-4/G-5 (`count<N`) —
  подставляются в P2.
- **Open questions:** — (Q1/Q2/Q3 разрешены P1.5; новых не заведено).
