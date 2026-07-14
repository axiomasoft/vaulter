# HANDOFF — 2026-07-14 — after P1.3

**Next:** `P1.4` — гейт-политика: каждая из 81 строки `SLO-nn` получает класс гейта (A/B по D14),
k6-метрику с тегом, выражение порога, baseline-политику (класс B) и **имя Prometheus-метрики** —
из последней колонки выводится **P8-минимум** (D16: список seam'ов, без которых P3–P7 не имеют
опоры для вердикта). Требует P1.3 (`slo.md`) — закрыт, item разблокирован.

| Параметр | Значение |
|:--|:--|
| Model | **opus** (§3 Routing плана: P1.4 — opus/high + tool-capability `context7`/`perplexity-web`) |
| Thinking | **high** — политика гейта + верификация синтаксиса k6 (RAG), «по памяти» = дефект |
| Context | continue (`/clear`) — ручной item |
| Суть | `slo-gates.md`: SLO → класс A/B, тегированные пороги k6, baseline как версионированный артефакт, раздел «P8-минимум» (counter/gauge/histogram), раздел «Что НЕ гейтим и почему», профили `smoke-perf` / `regression` |

```
/task:plan-exec VLT-LOAD P1.4
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка кодовой базы, RAG-провенанс инструментов
  (`findings/P0-rag-load-tooling.md`), мастер-план (`plan.md`, D1–D11).
- **P1 design** (2026-07-14) — 5 items (`phases/P1.md`), D12–D16, RAG `findings/P1-rag-slo-ci-gate.md`.
- **P1.1 🟠** (2026-07-14) — `reference-env.md`: тиры S/M/L (cpuset-конверт, `pm.max_children` 8/32/96,
  PG/PgBouncer/MinIO/генератор) + объёмный конверт `v_*` (1 млн просроченных сессий, 1,5 млн orphan,
  2 млн outbox-backlog, 1 000 grant-якорей, `v_activity` 500 млн). Конфликт «S не влезает в
  `ubuntu-latest`» → CI-профиль **S0** (D17-кандидат, Q3).
- **P1.2 🟢** (2026-07-14) — `operations-catalog.md`: 47 `OP-nn` (56 маршрутов обойдено, 38 в каталоге,
  18 исключено с причиной; 9 фоновых без HTTP-входа) + связка с L-01…L-08.
- **P1.3 🟢** (2026-07-14) — `slo.md`: **81 строка `SLO-nn`** = 27 групп × тир (S/M/L), покрытие всех
  47 OP. §0.1 бюджет FPM-пула (интерактив 4/16/48 + upload 2/8/24 + download 2/8/24 =
  `pm.max_children`); §0.4 error-budget (409 advisory-lock и 429 throttle — **не** ошибки, свои
  бюджеты); §2 стриминговые — throughput + **потолок RSS воркера**; §3 фоновые — тик + RSS +
  «backlog не растёт» (RAG-5). §5 разводит **доказанное** (SLO-46…48 / OP-07 / **L-01** — RSS ≈ размер
  файла, обязательство нарушено) и **под риском** (L-02…L-07 — подтверждаются named-фазами). §6 — пять
  вопросов владельцу (вход в P1.5). Все строки — `proposed`.

**Remaining:**
1. **P1 (2/5 🟢, +P1.1 🟠)** — P1.4 (гейт-политика + P8-минимум) → P1.5 (sign-off владельца:
   `proposed` → `approved`). Фаза — гейт для P2–P8.
2. **P2–P8** — скелеты; каждая разворачивается `/task:plan-design VLT-LOAD Pn` после закрытия
   предшествующей по порядку `P1 → P2 → P8-минимум → {P3,P4,P5,P6} → P7`.
3. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM) — SLO-46…48
   формально объявлены нарушенными; срез заблокирован **D11** до `vaulter-drive` ≥ 95 по VLT-MUT.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст с якорями `file:line`, §5 Decision Log (D1–D17), §4 доска,
  §7 Findings-долг (L-01…L-08).
- `plans/VLT-LOAD/reference-env.md` — тиры S/M/L + S0 (P1.1).
- `plans/VLT-LOAD/operations-catalog.md` — 47 `OP-nn` (P1.2).
- `plans/VLT-LOAD/slo.md` — **81 `SLO-nn`** (P1.3, прямой вход в P1.4: класс гейта на каждую строку).
- `plans/VLT-LOAD/phases/P1.md` — ТЗ пяти items.
- `plans/VLT-LOAD/findings/P0-rag-load-tooling.md` (RAG-5 — форма SLO очереди) ·
  `findings/P1-rag-slo-ci-gate.md` (RAG-6 — механика порогов k6, baseline; RAG-7 — cpuset).
- `plans/VLT-LOAD/open-questions.md` — Q1 (perf-раннер), Q2 (поддерживаемый тир), Q3 (S0 как CI-профиль).

**Open risks:**
- **Класс B (латентность §1 `slo.md`) на `ubuntu-latest` недостоверен** (D15): P1.4 обязан спроектировать
  гейт, работающий и без perf-раннера (класс B → advisory). Соблазн «объявить всё классом A и гейтить
  строго» даёт флапающий гейт, который отключат (RAG-6).
- **δ (допуск относительной регрессии)** выводится из CV окружения, а CV меряется только в P2 —
  в `slo-gates.md` δ обязан быть помечен `[гипотеза, уточняется в P2]`.
- **Числа L — экстраполяция** (`reference-env.md` §3): вердикт «L держит SLO» без прогона запрещён.
- **Агрегатные throughput'ы стримов** упираются в MinIO (1–2 ядра по конверту), а не в app. Если P2/P3
  покажут MinIO узким местом раньше app — правка **конверта**, а не SLO (эскалация в plan-design).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** строки §5 `slo.md` (кросс-ссылки «SLO под риском») обёрнуты в `**…**`, чтобы не
  ловиться row-регексами Validation P1.3 — иначе они дают 7 ложных «строк без тира». Профиль **S0**
  (`reference-env.md` §4.1) — обход ограничений `ubuntu-latest`, принимается/отклоняется владельцем (Q3).
- **Deferred:** `plan.md` §0 Meta (`Execution Mode`) и §4 (строка P2) всё ещё называют P8 «параллельной»
  — расхождение с D9 (P8 = гейт P3–P7); правка мастер-плана — работа `/task:plan-design`, не exec-item'а.
  L-02…L-08 — гипотезы, подтверждаются своими фазами; фикс любой drive-строки блокирован D11.
- **Open questions:** Q1 (perf-раннер) · Q2 (поддерживаемый тир в README — предложение P1.3: **M**) ·
  Q3 (S0 как CI-профиль) + пять вопросов §6 `slo.md` (латентные порядки; лаг фида ≤ 1 с; сквозной лаг
  webhook p95 ≤ 10 с; граница single-shot ≤ 32 MiB; поддерживаемый тир) — все сходятся в
  decision-sheet item'а **P1.5**.
