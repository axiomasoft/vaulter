# HANDOFF — 2026-07-14 — after P2.4

**Next:** `P2.5` — Гейт: thresholds-модуль (`load/gates/thresholds.js`) + perf-assert (PromQL,
`load/gates/perf-assert.py`) + первый baseline-артефакт. Обычный item-by-item exec (оркестрация не
заявлена).

| Параметр | Значение |
|:--|:--|
| Model | **sonnet** (§3 Routing: P2.1–P2.6, P2.8 — sonnet/medium, Exec = `plan-exec`; решения фазы уже приняты в дизайне — D18/D22–D28, механика/имена метрик — `harness.md`, `slo-gates.md`, сценарии — P2.4) |
| Thinking | **medium** — item транскрибирует `slo-gates.md` §2/§3 в исполняемый k6-threshold-модуль + PromQL-скрипт; имена метрик/тегов уже зафиксированы (P2.4), формат baseline — уже спроектирован (`slo-gates.md` §4.1) |
| Context | **continue (/clear) — manual item** |
| Суть | `load/gates/thresholds.js` (сквозные G-1…G-5 + §3, класс B advisory в отчёт, не в `options.thresholds`) + `load/gates/perf-assert.py` (PromQL по снимку + сравнение с baseline, вердикт pass/fail/not-checkable/incomparable) + `load/baselines/<profile>-<tier>.json` — первый артефакт |

```
/task:plan-exec VLT-LOAD P2.5
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
  `cpuset`/`mem_limit`/`memswap_limit`), три env-профиля (`S0`/`S`/`M`), SUT-образ nginx+php-fpm
  (`Dockerfile.app`, `entrypoint.sh`), `LoadServiceProvider`/`LoadActingAs`, `require-dev` S3-драйвер.
- **P2.3 🟢 Done** (2026-07-14, `/task:plan-exec`): `load:seed`/`load:activity-write` — объёмный+
  «грязный» конверт (`load/seed/profiles/{S0,S,M,L}.php`, backbone-64, wide-node, дерево+bulk-overflow,
  blob hot-set через реальный `BlobStore::commit()`+orphan GC-mark/sweep split, upload-сессии
  expired/healthy, many-grant/normal актор, outbox/webhook backlog, activity), манифест `dataset_id`,
  снапшот/restore.
- **P2.4 🟢 Done** (2026-07-14, `/task:plan-exec`): `load/k6/lib/*.js` (config/dataset/auth/fixtures/
  metrics/expected/headers/nodes/discovery) + `load/k6/scenarios/{tus-upload,s3-multipart,single-shot,
  download,tree,docs-collab,mixed}.js` + `load/k6/smoke.js` — все на стоковом k6 (D22). Новый
  `load/scripts/run.sh` (стейджит манифест P2.3 в `load/k6/dataset.json`, единственный путь его
  доставки в k6-контейнер). Живая проверка tier S0 (dataset `vlt-load-414fae92477d07f8`): все 8
  файлов проходят `k6 inspect`, контрактный smoke — **100 % checks (26/26), `http_req_failed` 0 %**,
  DB/MinIO пост-условия подтверждены (уникальный `content_sha256` на файл, размеры совпадают с
  объявленными, move/trash/restore/forceDelete прошли корректно). Два живых бага найдены и
  исправлены до зелёного прогона (`dataset.js` относительный путь `open()`; `tree.js` — many-grant
  актор слушал root вместо своих anchor-поддеревьев). Правки вне исходного Files item'а —
  `load/scripts/run.sh` (новый, без него Validation недостижима) и `load/app/testbench.load.yaml`
  (добавлен `VAULTER_COLLAB_SECRET` — без него OP-34 падал 500 до единого запроса) — детали см.
  phases/P2.md P2.4 Completion Notes/Known Deviations.

**Remaining:**
1. **P2 exec:** P2.1 ✅ → P2.2 ✅ → P2.3 ✅ → P2.4 ✅ → **P2.5 (гейт)** → P2.6 (CI) →
   **P2.7 (CV/δ — opus/high, `ЗАПУСК ВРУЧНУЮ`)** → P2.8 (DoD-прогон на тире S).
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик бо́льшая часть класса A
   вычисляется как `not-checkable`.
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); SLO-46…48
   формально нарушены; срез заблокирован **D11** (VLT-MUT drive ≥ 95).

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §3 Routing (**с колонкой Exec**), §4 доска (P2 4/8), §5
  Decision Log (**D1–D28**; D3/D4 — superseded), §7 Findings-долг (L-01…L-08), §8 Обсуждение.
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items; P2.1–P2.4 Completion Notes (P2.4 несёт живые
  фиксы и Known Deviations, читать перед P2.5).
- `plans/VLT-LOAD/harness.md` — SSOT механики харнесса (инструмент, стенд, фикстуры, executor'ы,
  контракт метрик, профили, лабораторные условия).
- `load/README.md` — как поднять/снести стенд + seed/activity-write/snapshot/restore + k6-сценарии
  (`run.sh`) + **предупреждение про `LOAD_APP_PORT`** (см. Open risks) + layout.
- `load/k6/` — библиотека + 7 сценариев + `smoke.js` (P2.4); `load/scripts/run.sh` — раннер.
- `load/seed/profiles/{S0,S,M,L}.php` — объёмные знобы seed'а (P2.3).
- `reference-env.md` (тиры + S0) · `operations-catalog.md` (47 OP) · `slo.md` (81 SLO) ·
  `slo-gates.md` (97 гейтов, P8-минимум) · `slo-signoff.md` (sign-off + roadmap).
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md` · `findings/P2-rag-k6-harness.md`.
- `open-questions.md` — Q1–Q3 разрешены, **Q4 открыт** (lab-box под тир M).

**Open risks:**
- **`LOAD_APP_PORT` обязан быть ОДИНАКОВЫМ на каждый `docker compose`-вызов этого стенда** (up,
  seed, `run.sh`, ad-hoc `exec`) — иначе compose пересчитывает `app`'s `ports:`, пересоздаёт
  контейнер, и `entrypoint.sh` гоняет `migrate:fresh --force` заново, молча стирая датасет. Найдено
  живьём при закрытии P2.4 (несколько часов потрачено на диагностику «почему 404 там, где только что
  было 200»); задокументировано в README, но **следующий исполнитель обязан `export
  LOAD_APP_PORT=<port>` на всю сессию**, а не полагаться на память между отдельными командами.
- **Тир M — публичное обещание без прогона (Q4/D25).** README обещает числа M (D19/Q2), а честного
  M-стенда нет: 12 однородных ядер на i7-14700K не собрать.
- **Бо́льшая часть гейта в P2 будет `not-checkable`** — серверные метрики (RSS, батч sweeper'а,
  lock-set, backlog) появляются только в P8. perf-assert (P2.5) обязан это печатать, а не выдавать
  зелёный.
- **δ = 10 % не измерен** — до P2.7 относительный гейт класса B не имеет доказанной чувствительности.
- **Ожидаемый красный в P2.5/P2.8:** S3-`complete()` на большом файле может 5xx (L-01) —
  `s3-multipart.js` уже переживает это как известный красный (не смотрено в P2.5 порогом, который бы
  его скрыл).
- **Постфактум-загадка вне харнесса (P2.4 Pending Work):** прямой curl на существующий узел ВНЕ
  k6-прогона иногда стабильно 404-ит без записи в `laravel.log`, хотя ТОТ ЖЕ маршрут/узел только что
  резолвился внутри самого k6-прогона. Не воспроизведено через сам харнесс (единственный измеряемый
  канал), причина не установлена — если проявится в P2.5/P2.8 через сам k6/perf-assert, разобрать
  тогда; сама Validation P2.4 не блокирована.
- **Числа L-долгов** (§7 `plan.md`) — экстраполяция, гейта в CI не имеют (`slo-gates.md` §6.2).
- **Тиры S/M живьём не прогнаны** (только S0 во всех items P2.2–P2.4) — профили/сценарии на бо́льшем
  объёме не верифицированы прогоном; `VUS_*`/`DURATION` env-override предусмотрен в k6-скриптах.

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память в P2 снимается с cgroup контейнера (`memory.peak`), а не
  per-request (`vaulter_request_peak_memory_bytes` появится в P8) — грубый заменитель, ограничение
  фиксируется в P2.5. Миграции SUT идут напрямую в Postgres, минуя PgBouncer (transaction-mode +
  DDL/prepared-statements — известная несовместимость) — `entrypoint.sh`. `load/app/testbench.load.yaml`
  несёт dev-only `VAULTER_COLLAB_SECRET` (P2.4) — стенд никогда не говорит с реальным Node-хабом.
  Discovery для download/docs-collab сценариев строится ОДИН РАЗ в k6 `setup()` реальными HTTP-
  вызовами (`load/k6/lib/discovery.js`), а не из манифеста — манифест P2.3 несёт только anchor-id'ы.
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. **lab-box под тир M**); полный набор
  P8-метрик; k6-Operator/распределённый прогон (не нужен в P2 — RAG-13); живой прогон seed'а+сценариев
  на тирах S/M; постфактум-загадка про curl-404 вне k6 (P2.4 Pending Work).
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`, не
  блокирует P2).
