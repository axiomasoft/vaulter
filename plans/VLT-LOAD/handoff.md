# HANDOFF — 2026-07-14 — after P1.2

**Next:** `P1.3` — SLO-таблица: latency/throughput/error-budget по `OP-nn` × тир (`SLO-nn`). Требует
и P1.1 (`reference-env.md` — тиры S/M/L), и P1.2 (`operations-catalog.md` — 47 `OP-nn`) — оба
закрыты, item разблокирован. Это опорный артефакт фазы: числа-обязательства, не измерения.

| Параметр | Значение |
|:--|:--|
| Model | **opus** (§3 Routing плана: P1.1, **P1.3** — opus/high, «цифры-обязательства каскадят на все фазы; ошибка тут инвалидирует программу») |
| Thinking | **high** — числа, не механика; ошибка каскадит на P2–P8 |
| Context | continue (`/clear`) — ручной item |
| Суть | для каждого `OP-nn` × тира (S/M/L) — конкуренция/RPS, размерный профиль, p50/p95/p99 или throughput/RSS (для стриминговых), error-budget, статус `proposed`; полное покрытие OP→SLO |

```
/task:plan-exec VLT-LOAD P1.3
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка кодовой базы, RAG-провенанс инструментов
  (`findings/P0-rag-load-tooling.md`), мастер-план (`plan.md`, D1–D11).
- **P1 design** (2026-07-14) — 5 items (`phases/P1.md`), D12–D16, RAG `findings/P1-rag-slo-ci-gate.md`.
- **P1.1 🟠** (2026-07-14) — `reference-env.md`: три тира S/M/L (железный конверт на cpuset —
  `--cpus` запрещён по RAG-7; PG/PgBouncer/MinIO/генератор; `pm.max_children` 8/32/96) + объёмный
  конверт по реальным `v_*`-таблицам (1 млн просроченных сессий и 1,5 млн orphan-блобов на L —
  D-LOAD-3; 1 000 grant-якорей = 1 000 рекурсивных CTE, L-06; `v_activity` 500 млн строк +
  write-RPS) + назначение тиров + лабораторные условия. Конфликт: тир S не помещается в
  `ubuntu-latest` → усечённый CI-профиль **S0** — D17-кандидат + Q3 владельцу.
- **P1.2 🟢** (2026-07-14) — `operations-catalog.md`: обход 56 `Route::` в
  `core`/`drive`/`documents` (`media`/`filament` собственных `routes/*.php` не несут) + `Console/` +
  `Webhooks/` + `Collaboration/`. 47 `OP-nn` (38 route-based: tus 5, S3-multipart 2, download 3,
  дерево 14, активность 2, документы 7, коллаборация/комментарии 5; 9 фоновых без HTTP-входа:
  outbox stage, relay-tick, DeliverWebhook job, retry-sweep, prune, sweep-uploads, purge-trash,
  gc-blobs, reconcile-blobs). 18 исключённых маршрутов — каждый с причиной. Связка с
  Findings-долгом: L-01→OP-07, L-02→OP-44, L-03→OP-46, L-04→OP-42, L-05→OP-40, L-06→OP-12/13/14/20,
  L-08→OP-44 (и все artisan-команды §8–§9). Новых находок не всплыло.

**Remaining:**
1. **P1 (1/5 🟢, +P1.1 🟠)** — P1.3 (числа SLO) → P1.4 (гейт-политика + P8-минимум) →
   P1.5 (sign-off владельца). Фаза — гейт для P2–P8.
2. **P2–P8** — скелеты; каждая разворачивается `/task:plan-design VLT-LOAD Pn` после закрытия
   предшествующей по порядку `P1 → P2 → P8-минимум → {P3,P4,P5,P6} → P7`.
3. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM; конверт P1.1
   §4.2 подтвердил арифметикой: 5 ГиБ не помещаются в `mem_limit` app ни на одном тире) —
   заблокирован D11 до `vaulter-drive` ≥ 95 по VLT-MUT.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст с якорями `file:line`, §5 Decision Log (D1–D17), §4 доска,
  §7 Findings-долг (L-01…L-08).
- `plans/VLT-LOAD/reference-env.md` — тиры S/M/L + S0 (P1.1, вход для P1.3, P2, P3, P5/P6, P8).
- `plans/VLT-LOAD/operations-catalog.md` — **47 `OP-nn`** (P1.2, вход для P1.3, P2, P3).
- `plans/VLT-LOAD/phases/P1.md` — ТЗ пяти items.
- `plans/VLT-LOAD/findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` — RAG-провенанс.
- `plans/VLT-LOAD/open-questions.md` — Q1 (perf-раннер), Q2 (поддерживаемый тир в README),
  Q3 (принять ли S0 как CI-профиль вместо тира S).

**Open risks:**
- **Q3.** Если владелец не примет S0, CI-гейта нагрузки не будет вовсе (S в `ubuntu-latest` не
  помещается) — либо потребуется perf-раннер (Q1). Дизайн P1.4 обязан работать в обоих случаях.
- **Q1 (perf-раннер).** Без него латентный класс B остаётся advisory: CI ловит катастрофу и рост
  ошибок, но не мягкую регрессию p95.
- **δ (допуск относительной регрессии)** выводится из CV окружения, а CV меряется только в P2 —
  P1.4 обязан пометить δ гипотезой.
- **Числа тиров — `[гипотеза]`.** Тир L до первого прогона — экстраполяция; вердикт «L держит SLO»
  без прогона запрещён (`reference-env.md` §3).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** профиль **S0** — обходной путь вокруг ограничений `ubuntu-latest` (не тир
  развёртывания, а шумоустойчивый сторож класса A); принимается/отклоняется владельцем (Q3).
- **Deferred:** `plan.md` §0 Meta (`Execution Mode`) и §4 (строка P2) всё ещё называют P8
  «параллельной» — расхождение с D9 (P8 = гейт P3–P7), унаследованное от первой редакции; правка
  мастер-плана — работа `/task:plan-design`, не exec-item'а. L-02…L-08 — гипотезы, подтверждаются в
  своих фазах; фикс любой drive-строки блокирован D11.
- **Open questions:** Q1 (perf-раннер) · Q2 (поддерживаемый тир в README) · Q3 (S0 как CI-профиль) —
  все три сходятся в decision-sheet item'а **P1.5**.
