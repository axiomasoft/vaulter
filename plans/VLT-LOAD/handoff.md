# HANDOFF — 2026-07-14 — after P1.4

**Next:** `P1.5` — **человеческий гейт**: sign-off владельца. Собрать decision-sheet
(`slo-signoff.md`) из уже готовых входов — §6 `slo.md` (5 вопросов), Q1/Q2/Q3 `open-questions.md`,
δ=10 % и условия перехода класса B в блокирующий (`slo-gates.md` §4.4) — получить ответы владельца,
перевести строки `proposed` → `approved`, завести D-записи и **закрыть фазу P1** (она — гейт для
P2–P8). Item НЕ закрывается 🟢 без ответов владельца (if-then ТЗ: иначе 🔴 Blocked, P2 не стартует).

| Параметр | Значение |
|:--|:--|
| Model | **sonnet** (§3 Routing плана: P1.5 — «человек (владелец) + sonnet/low на механическую правку статусов»; исполнитель лишь готовит sheet и переносит ответ) |
| Thinking | **medium** — решений не принимает; аккуратность важнее глубины (правка 81 строки статусов + D-записи) |
| Context | continue (`/clear`) — ручной item |
| Суть | `slo-signoff.md`: вопрос · предложение · последствие; после ответа — `proposed`→`approved` в `slo.md`/`slo-gates.md`, D-записи в `plan.md` §5 (в т.ч. D17/S0 и D18-кандидат из P1.4), Phase Handoff P1 |

```
/task:plan-exec VLT-LOAD P1.5
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка кодовой базы, RAG-провенанс инструментов
  (`findings/P0-rag-load-tooling.md`), мастер-план (`plan.md`, D1–D11).
- **P1 design** (2026-07-14) — 5 items (`phases/P1.md`), D12–D16, RAG `findings/P1-rag-slo-ci-gate.md`.
- **P1.1 🟠** — `reference-env.md`: тиры S/M/L (cpuset-конверт, `pm.max_children` 8/32/96) + объёмный
  конверт `v_*`. Конфликт «S не влезает в `ubuntu-latest`» → CI-профиль **S0** (D17-кандидат, Q3).
- **P1.2 🟢** — `operations-catalog.md`: 47 `OP-nn` (56 маршрутов обойдено, 18 исключено с причиной).
- **P1.3 🟢** — `slo.md`: **81 `SLO-nn`** (27 групп × S/M/L), покрытие всех 47 OP; §0.1 бюджет
  FPM-пула, §0.4 error-budget (409/429 — **не** ошибки), §5 доказанное (L-01) vs под риском (L-02…07).
- **P1.4 🟢** (2026-07-14) — `slo-gates.md`: **97 строк гейта на 81 SLO**. Класс **A**
  (throughput/RSS/батч/lock-set/backlog/error-rate) — абсолютный, **блокирующий**; класс **B**
  (вся латентность) — guardrail ≈150 % + относительная регрессия к baseline, **advisory** до
  perf-раннера (D15). **Две плоскости оценки** (RAG-8b): `k6-thresholds` (exit 99) + `perf-assert`
  (PromQL — серверные метрики, порогом k6 невыразимы) ⇒ **D18-кандидат**. §5 — **P8-минимум**:
  37 метрик с типом/лейблами + `/up`, выведен ИЗ SLO (D9/D16), сверен с блупринтом `phases/P8.md`
  (2 метрики выпали, 7 добавлены под L-долги). §7 — профили `smoke-perf` (PR, S0, класс A) /
  `regression` (nightly) / `lab` (класс B блокирующий). RAG — `findings/P1.4-rag-k6-threshold-syntax.md`.

**Remaining:**
1. **P1 (3/5 🟢, +P1.1 🟠)** — остался **P1.5** (sign-off владельца). Фаза — гейт для P2–P8.
2. **P2–P8** — скелеты; каждая разворачивается `/task:plan-design VLT-LOAD Pn` после закрытия
   предшествующей по порядку `P1 → P2 → P8-минимум → {P3,P4,P5,P6} → P7`.
3. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); SLO-46…48
   формально нарушены, гейт красный до фикса; срез заблокирован **D11** (VLT-MUT drive ≥ 95).

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст с якорями, §5 Decision Log (D1–D17 + D18-кандидат в
  Pending Work P1.4), §4 доска, §7 Findings-долг (L-01…L-08).
- `plans/VLT-LOAD/reference-env.md` (тиры + S0) · `operations-catalog.md` (47 OP) ·
  `slo.md` (81 SLO, все `proposed`) · **`slo-gates.md` (гейты, baseline-политика, P8-минимум)**.
- `plans/VLT-LOAD/phases/P1.md` — ТЗ пяти items; `open-questions.md` — Q1/Q2/Q3.
- RAG: `findings/P0-rag-load-tooling.md` (k6/OTel/очереди) · `findings/P1-rag-slo-ci-gate.md`
  (RAG-6 механика порогов, RAG-7 cpuset) · `findings/P1.4-rag-k6-threshold-syntax.md`
  (RAG-8 агрегации кастомных метрик; RAG-8b — чего пороги k6 выразить не могут).

**Open risks:**
- **δ = 10 % — гипотеза.** Выводится из CV окружения, а CV меряется только в **P2** (5 прогонов).
  Пока не измерен, относительный гейт класса B не имеет доказанной чувствительности.
- **Класс B на `ubuntu-latest` недостоверен** (D15) — он advisory; значит **до появления perf-раннера
  (Q1) блокирующим является только класс A**. Если владелец ожидает от CI-гейта латентной защиты —
  это ожидание сегодня не покрыто, и P1.5 обязан это ему сказать явно.
- **Половина класса A вычисляется вне k6** (perf-assert/PromQL) ⇒ она **зависит от фазы P8**: пока
  seam'ов §5 нет, эти пороги нечем считать. P8-минимум — гейт P3–P7 не на словах.
- **Числа L — экстраполяция**; гейта в CI не имеют вовсе (`slo-gates.md` §6.2).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** строка §6.3 `slo-gates.md` обёрнута в `**…**` (кросс-ссылка, не строка гейта —
  иначе ловится row-регексом Validation); тот же приём, что в §5 `slo.md`. `context7` исчерпал
  месячную квоту — RAG-8 снят через `perplexity-web` двумя запросами (см. Known Deviations P1.4).
- **Deferred:** **D18-кандидат** (две плоскости гейта) — запись в `plan.md` §5 делает
  plan-design/P1.5, не exec. `plan.md` §0 Meta (`Execution Mode`) и §4 (строка P2) всё ещё называют
  P8 «параллельной» — расхождение с D9 (P8 = гейт); правка мастер-плана — работа `/task:plan-design`.
  Абсолютные `count<N` в порогах G-4/G-5 подставляются в P2 (нужно число итераций профиля).
- **Open questions:** Q1 (perf-раннер — определяет блокирующность класса B) · Q2 (поддерживаемый тир
  в README — предложение: **M**) · Q3 (S0 как CI-профиль) + пять вопросов §6 `slo.md` — все сходятся
  в decision-sheet item'а **P1.5**.
