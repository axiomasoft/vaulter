# HANDOFF — 2026-07-14 — after P1.5

**Next:** `P2.1` — написать `plans/VLT-LOAD/harness.md` (инструмент, стенд, контракт метрик, раскладка
профилей). Фаза P2 **спроектирована** (8 items, DoR пройден) — следующий шаг обычный item-by-item
exec, оркестрация не заявлена (items связаны по Inputs↔Deliverables последовательно).

| Параметр | Значение |
|:--|:--|
| Model | **sonnet** (§3 Routing: P2.1–P2.6, P2.8 — sonnet/medium, Exec = `plan-exec`; решения фазы уже приняты в дизайне — D22–D28) |
| Thinking | **medium** — item транскрибирует принятые решения в спеку харнесса; выбора инструмента в нём больше нет |
| Context | **continue (/clear) — manual item** |
| Суть | `harness.md`: стоковый k6 (D22, без xk6), `--out experimental-prometheus-rw` (D24), фикстуры в памяти + salt (D23), executor'ы по `slo.md` §0.1 (4/16/48 · 2/8/24 · 2/8/24), контракт 5 кастомных метрик и тегов, три профиля (`slo-gates.md` §7) |

```
/task:plan-exec VLT-LOAD P2.1
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс инструментов, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` (тиры S/M/L + CI-профиль S0) · `operations-catalog.md`
  (47 `OP-nn`) · `slo.md` (**81 `SLO-nn`**, все `approved`) · `slo-gates.md` (**97 гейтов**, класс A
  блокирующий / класс B advisory, две плоскости оценки, P8-минимум = 37 метрик + `/up`) ·
  `slo-signoff.md` (sign-off владельца, Q1–Q3 разрешены, D19–D21).
- **P2 — спроектирована** (2026-07-14, `/task:plan-design`): `phases/P2.md`, 8 items, DoR пройден.
  RAG (`findings/P2-rag-k6-harness.md`) **опроверг два решения блупринта**:
  - **D22** (supersedes D3): харнесс — **стоковый k6**. `xk6-fasthttp` не эмитит
    `http_req_duration`/`http_req_failed`/`data_sent`/`data_received` — метрики, на которых стоит весь
    гейт P1.4; PATCH (основа tus) не документирован. И стриминг тела vaulter'у не нужен: ни один
    эндпоинт не принимает multi-GB тело одним запросом.
  - **D24** (supersedes D4): Prometheus remote-write **в ядре k6** с v0.42 (`--out
    experimental-prometheus-rw`) — кастомный бинарник не собирается.
  - **D23**: фикстуры — общий `ArrayBuffer` + salt в первом чанке (у `k6/experimental/fs` — полная
    копия файла в RAM ⇒ 5-ГиБ фикстура положила бы генератор).
  - **D25**: авторитетный тир прогонов — **S** (M на доступном железе однородного cpuset'а не
    получает); **D26** (`load/` в этом репо), **D27** (push `load/harness` разрешён владельцем),
    **D28** (SUT — nginx+php-fpm, не `testbench serve`).

**Remaining:**
1. **P2 exec:** P2.1 (harness.md) → P2.2 (стенд) → P2.3 (seed) → P2.4 (сценарии) → P2.5 (гейт) →
   P2.6 (CI) → **P2.7 (CV/δ — opus/high, `ЗАПУСК ВРУЧНУЮ`)** → P2.8 (DoD-прогон на тире S).
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик бо́льшая часть класса A
   вычисляется как `not-checkable`.
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); SLO-46…48
   формально нарушены; срез заблокирован **D11** (VLT-MUT drive ≥ 95).

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §3 Routing (**с колонкой Exec**), §4 доска, §5 Decision Log
  (**D1–D28**; D3/D4 — superseded), §7 Findings-долг (L-01…L-08), §8 Обсуждение (развилки со
  статусами).
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items.
- `reference-env.md` (тиры + S0) · `operations-catalog.md` (47 OP) · `slo.md` (81 SLO) ·
  `slo-gates.md` (97 гейтов, P8-минимум) · `slo-signoff.md` (sign-off + roadmap).
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md` · **`findings/P2-rag-k6-harness.md`**.
- `open-questions.md` — Q1–Q3 разрешены, **Q4 открыт** (lab-box под тир M).

**Open risks:**
- **Тир M — публичное обещание без прогона (Q4/D25).** README обещает числа M (D19/Q2), а честного
  M-стенда нет: 12 однородных ядер на i7-14700K не собрать. Авторитетный тир — S; M — класс A /
  экстраполяция. Пока Q4 открыт, любое утверждение «пакет держит M» не подкреплено.
- **Бо́льшая часть гейта в P2 будет `not-checkable`** — серверные метрики (RSS, батч sweeper'а,
  lock-set, backlog) появляются только в P8. perf-assert обязан это печатать, а не выдавать зелёный:
  «зелёный smoke-perf» до P8 означает «класс A клиентской плоскости», не «SLO держатся».
- **δ = 10 % не измерен** — до P2.7 относительный гейт класса B не имеет доказанной чувствительности.
- **Ожидаемый красный в P2.4/P2.8:** S3-`complete()` на большом файле упадёт (L-01). Это
  подтверждение находки, а не баг харнесса — уменьшать файл «чтобы прошло» запрещено.
- **Числа L-долгов** (§7 `plan.md`) — экстраполяция, гейта в CI не имеют (`slo-gates.md` §6.2).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память в P2 снимается с cgroup контейнера (`memory.peak`), а не
  per-request (`vaulter_request_peak_memory_bytes` появится в P8) — грубый заменитель, ограничение
  зафиксировано в P2.5.
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. **lab-box под тир M**); полный набор
  P8-метрик; k6-Operator/распределённый прогон (не нужен в P2 — RAG-13).
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`, не
  блокирует P2).
