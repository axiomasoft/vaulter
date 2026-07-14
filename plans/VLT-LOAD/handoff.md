# HANDOFF — 2026-07-14 — after P2.2

**Next:** `P2.3` — seed: объёмный конверт и «грязные» датасеты (`dataset_id`, снапшот).
Обычный item-by-item exec (оркестрация не заявлена).

| Параметр | Значение |
|:--|:--|
| Model | **sonnet** (§3 Routing: P2.1–P2.6, P2.8 — sonnet/medium, Exec = `plan-exec`; решения фазы уже приняты в дизайне — D22–D28, механика стенда — `harness.md`/P2.2) |
| Thinking | **medium** — item пишет artisan-команду seed'а поверх УЖЕ существующей схемы `PanelTablesBuilder` и УЖЕ поднятого стенда (P2.2); архитектурного выбора не осталось, только объёмы из `reference-env.md` §2 |
| Context | **continue (/clear) — manual item** |
| Суть | `load/app/Console/SeedLoadDatasetCommand.php` + `ActivityWriterCommand.php`, профили объёмов S0/S/M, снапшот/restore-скрипты, манифест `dataset_id` с id-списками, scope-проверки (sweeper/GC видят «грязные» строки) |

```
/task:plan-exec VLT-LOAD P2.3
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс инструментов, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` (тиры S/M/L + CI-профиль S0) · `operations-catalog.md`
  (47 `OP-nn`) · `slo.md` (**81 `SLO-nn`**, все `approved`) · `slo-gates.md` (**97 гейтов**, класс A
  блокирующий / класс B advisory, две плоскости оценки, P8-минимум = 37 метрик + `/up`) ·
  `slo-signoff.md` (sign-off владельца, Q1–Q3 разрешены, D19–D21).
- **P2 — спроектирована** (2026-07-14, `/task:plan-design`): `phases/P2.md`, 8 items, DoR пройден.
  RAG (`findings/P2-rag-k6-harness.md`) опроверг два решения блупринта — D22 (стоковый k6, supersedes
  D3), D24 (Prometheus в ядре k6, supersedes D4), D23, D25 (тир S авторитетный; Q4), D26 (`load/` в
  этом репо), D27 (push `load/harness` разрешён владельцем), D28 (SUT — nginx+php-fpm).
- **P2.1 🟢 Done** (2026-07-14, `/task:plan-exec`): `plans/VLT-LOAD/harness.md` — 9 разделов
  (инструмент/стенд/фикстуры/executor'ы/контракт метрик/профили/лабораторные условия/границы/куда
  уходят решения) — транскрипция D22–D28, ни строки в `load/**`/`packages/*/src`.
- **P2.2 🟢 Done** (2026-07-14, `/task:plan-exec`): `load/` поднят и провалидирован живьём —
  `docker-compose.load.yml` (postgres/pgbouncer/minio+init/app/prometheus/k6; конверт только
  `cpuset`/`mem_limit`/`memswap_limit`, `deploy:`/`cpus:` отсутствуют — подтверждено grep), три
  env-профиля (`S0`/`S`/`M` — cpuset тира S переведён в физические CPU-id ЭТОГО lab-box'а через
  `lscpu -e`), SUT-образ nginx+php-fpm (`Dockerfile.app`, `entrypoint.sh` рендерит
  `pm.max_children`, мигрирует напрямую в Postgres мимо PgBouncer), `LoadServiceProvider`/
  `LoadActingAs` (зеркалят `E2eServiceProvider`), `require-dev` S3-драйвер. Живая проверка (не
  только `config`/healthcheck): tier S0 и S подняты docker-compose'ом, `GET /load/health` → 200,
  `GET /load/storage` → 200 (собственный шов вместо несуществующего `POST storages`), `POST
  storages/{id}/folders` → 201 (узел реально создан), `docker inspect` на тире S подтвердил
  cpuset/mem_limit=memswap_limit, `pm.max_children`=8 в живом `www.conf`. `composer test` 499/499,
  `composer analyse` 0 errors, `pint --test load/app` passed. Все контейнеры/тома снесены после
  проверки — детали см. phases/P2.md P2.2 Completion Notes/Known Deviations.

**Remaining:**
1. **P2 exec:** P2.1 ✅ → P2.2 ✅ → **P2.3 (seed)** → P2.4 (сценарии) → P2.5 (гейт) → P2.6 (CI) →
   **P2.7 (CV/δ — opus/high, `ЗАПУСК ВРУЧНУЮ`)** → P2.8 (DoD-прогон на тире S).
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик бо́льшая часть класса A
   вычисляется как `not-checkable`.
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); SLO-46…48
   формально нарушены; срез заблокирован **D11** (VLT-MUT drive ≥ 95).

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §3 Routing (**с колонкой Exec**), §4 доска (P2 2/8), §5
  Decision Log (**D1–D28**; D3/D4 — superseded), §7 Findings-долг (L-01…L-08), §8 Обсуждение.
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items; P2.1/P2.2 Completion Notes.
- `plans/VLT-LOAD/harness.md` — SSOT механики харнесса (инструмент, стенд, фикстуры, executor'ы,
  контракт метрик, профили, лабораторные условия) — P2.2 реализует его §2 буквально.
- `load/README.md` — как поднять/снести стенд, layout, известное ограничение тира M на этом хосте.
- `reference-env.md` (тиры + S0) · `operations-catalog.md` (47 OP) · `slo.md` (81 SLO) ·
  `slo-gates.md` (97 гейтов, P8-минимум) · `slo-signoff.md` (sign-off + roadmap).
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md` · `findings/P2-rag-k6-harness.md`.
- `open-questions.md` — Q1–Q3 разрешены, **Q4 открыт** (lab-box под тир M).

**Open risks:**
- **Тир M — публичное обещание без прогона (Q4/D25).** README обещает числа M (D19/Q2), а честного
  M-стенда нет: 12 однородных ядер на i7-14700K не собрать. `load/env/M.env` поднимается БЕЗ cpuset
  (класс A only, латентность недостоверна) — подтверждено на практике при закрытии P2.2.
- **Бо́льшая часть гейта в P2 будет `not-checkable`** — серверные метрики (RSS, батч sweeper'а,
  lock-set, backlog) появляются только в P8. perf-assert обязан это печатать, а не выдавать зелёный.
- **δ = 10 % не измерен** — до P2.7 относительный гейт класса B не имеет доказанной чувствительности.
- **Ожидаемый красный в P2.4/P2.8:** S3-`complete()` на большом файле упадёт (L-01). Это
  подтверждение находки, а не баг харнесса — уменьшать файл «чтобы прошло» запрещено.
- **Числа L-долгов** (§7 `plan.md`) — экстраполяция, гейта в CI не имеют (`slo-gates.md` §6.2).
- **Хост-специфика:** порт 8080 на lab-box'е конфликтовал с посторонним контейнером — `up.sh`
  уважает `LOAD_APP_PORT`, но следующий исполнитель на ДРУГОЙ машине не обязан это знать заранее.

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память в P2 снимается с cgroup контейнера (`memory.peak`), а не
  per-request (`vaulter_request_peak_memory_bytes` появится в P8) — грубый заменитель, ограничение
  зафиксировано в P2.5 (ещё не реализовано). Миграции SUT идут напрямую в Postgres, минуя PgBouncer
  (transaction-mode + DDL/prepared-statements — известная несовместимость) — `entrypoint.sh`.
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. **lab-box под тир M**); полный набор
  P8-метрик; k6-Operator/распределённый прогон (не нужен в P2 — RAG-13); `load/k6/*.js` (P2.4).
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`, не
  блокирует P2).
