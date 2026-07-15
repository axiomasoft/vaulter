# VLT-LOAD — Программа нагрузочной готовности vaulter

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-LOAD |
| Title | Нагрузочная готовность vaulter: SLO → сценарии → профилирование → память/стриминг → конкуренция → очереди → деградация → наблюдаемость |
| Version | 0.3.0 |
| Status | 🟡 In progress (P0 🟢; P1 🟢 Done — 5/5, sign-off получен; **P2 🟢 Done — 8/8: 5 🟢 + P2.6/P2.7/P2.8 🟠; next — P8-минимум**) |
| Document Type | Executable Master Plan (design-first: до P3 — артефакты и решения, не код) |
| Authoring Model | opus/high (design-профиль task:design; см. §3 Routing — все фазы этого плана роутятся на opus/high) |
| Last Updated | 2026-07-14 |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-drive, vaulter-documents, vaulter-media, vaulter-filament |
| Стек | PHP 8.4, Laravel 12/13-contracts, Postgres 16, Flysystem+S3, tus/S3-multipart, staudenmeir HasRecursiveRelationships, Laravel queues, Reverb/Hocuspocus |
| Execution Mode | phase-first: P1 (SLO) — гейт для всего; P2 (харнесс) — гейт для P3–P7; P8 (наблюдаемость) — **тоже гейт** P3–P7 по минимальному набору seam'ов (D9; формулировка «параллельна» первой редакции снята) |
| Ось | **поведение под нагрузкой**, НЕ корректность (это ось VLT-MUT). Планы не пересекаются. |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-LOAD |
| Depends on | **Проектирование** — независимо от VLT-MUT (VLT-REMED/VLT-CANON в архиве). **Исполнение фиксов** (dev-срезы горячих путей, D-LOAD-5) — зависит от VLT-MUT по файлу: срез не открывается, пока VLT-MUT не закрыл `vaulter-drive` до Score ≥ 95 (см. D11). |
| Branch | `remediation/audit-vlt` (design-фаза — коммитим только артефакты plans/; код фаз — по решению владельца) |

> **Как поднять из файла после /clear:** читать этот `plan.md` целиком + `findings/P0-rag-load-tooling.md`
> и `findings/P1-rag-slo-ci-gate.md` (провенанс внешних фактов: k6/Postgres/очереди/OTel; CI-гейт на
> shared-раннерах; docker cpuset vs квота — верифицировано через RAG). Факты рекона кодовой базы
> (горячие пути, индексы, память-vs-стриминг, unbounded-scan'ы) вплавлены в §1 — с точными `file:line`.
> Детализация фаз и ТЗ items — в `phases/Pn.md`; текущая точка — `handoff.md`. Чата не требуется.

---

## 1. Context

### 1.1 Зачем отдельный план (не фаза VLT-MUT)

VLT-MUT меряет **корректность** (мутационный гейт: убивают ли тесты мутантов). VLT-LOAD меряет **поведение
под нагрузкой**: латентность/throughput/память/контеншн под сотнями параллельных клиентов и multi-GB
файлами. Оси ортогональны — 100 % MSI ничего не говорит о том, что `S3MultipartHandler::complete()`
собирает 5-ГиБ файл целиком в PHP-строку. Отсюда отдельный план, второй в `ACTIVE.md`, не вместо.

### 1.2 Что уже известно из рекона (опорные факты — с якорями в коде)

Ниже — то, что делает план не абстрактным. Каждый пункт — измеримая гипотеза для фазы.

**Память / стриминг (фаза P4):**
- ⚠ **Топ-риск памяти:** `S3MultipartHandler::complete()` конкатенирует ВСЕ парты в одну PHP-строку
  `$assembled` и держит `strlen($assembled)` байт — **весь собранный файл в RAM разом**
  (`packages/drive/src/Uploads/S3MultipartHandler.php:152-188`, `readPart()` :249-264). Для 5 ГиБ ×
  N параллельных commit'ов это N×5 ГиБ пиковой памяти FPM. `max_chunk_bytes` (64 MiB) бьёт только по
  одному парту (`readPartWithinLimit()` :230-247), НЕ по сумме.
- Tus-путь напротив **стримит**: PATCH-тело как resource → `StagedUpload::append()` spill-to-disk 2 MiB
  (`TusUploadHandler.php:83-137`), контроллер `getContent(asResource: true)` (`TusUploadController.php:370`).
- CAS-хеширование **стримит**: `Sha256ContentStore::stage()` в локальный temp по 1 MiB, `hash_update`
  инкрементально (`packages/media/src/Cas/Sha256ContentStore.php:37-78`); `BlobStore::commit()` хеширует
  ВНЕ транзакции, лок держит только на liveness (`packages/media/src/Cas/BlobStore.php:52-94`).
- Download **стримит** 256 KiB через `streamDownload` (`packages/drive/src/Http/Controllers/DownloadController.php:112-140`),
  НО **всегда через PHP, без presigned-redirect** (решение владельца — **AUDIT-VLT D15**, не D15 этого
  плана) — каждое скачивание занимает PHP-воркер на всю длительность (`:26-31`). Один blob на версию,
  мульти-blob reassembly на download-пути нет (`:90-106`).

**Конкуренция (фаза P5):**
- Per-session критическая секция — NOWAIT advisory-lock, общий для PATCH/putPart/commit/abort/expire:
  pgsql `pg_try_advisory_xact_lock(hashtextextended('upload:'+id,0))` — **64-бит** ключ (R-09 фикс),
  false → 409/skip (`packages/drive/src/Repositories/Uploads/UploadSessionStoreRepository.php:80-114`).
- CAS-лок: `pg_advisory_xact_lock(hashtext(scope|sha256))` — **int4** ключ, коллизии при масштабе дедупа
  (`packages/core/src/Cas/BlobLock.php:35-49`); guards GC-vs-upload backbone.
- `tests/Concurrency/` симулирует **максимум 2 процесса / 2 соединения** — нет N>2 контеншена, нет
  sustained-throughput, нет storm дедупа/reparent (`packages/drive/tests/Concurrency/` — 2 файла).
- Дерево: `candidateNodeIds()` делает **один recursive-CTE спуск НА КАЖДЫЙ grant-anchor** — N-CTE hot
  spot для many-grant актора (`packages/core/src/Repositories/Drive/NodeReadRepository.php:454-529`).
  `MoveNodeAction::assertNoCycle()` грузит ПОЛНЫЙ subtree в PHP и `in_array` — move = O(subtree)
  (`packages/drive/src/Actions/MoveNodeAction.php:85-100`).

**Sweeper / GC при масштабе (фазы P5, P6):**
- Sweeper просроченных сессий: `->get()` — **материализует ВСЕ** кандидаты без limit/chunk
  (`packages/drive/src/Console/SweepUploadsCommand.php:37`); scope `(status, expires_at)` бэкает индекс
  `_sweeper_idx`. **Не зарегистрирован в расписании пакетом** — хост обязан завести cron.
- GC блобов: `candidates()` через `->cursor()` **без batch-limit** — mark/sweep обходят каждую строку,
  одна `DB::transaction` + advisory-lock + `FOR UPDATE` на блоб; при миллионах orphan'ов — миллионы
  локед-транзакций в одном вызове без чекпоинта/бюджета (`packages/media/src/Cas/BlobGarbageCollector.php:293-298`,
  SERIALIZABLE+retry :246-265).

**Очереди / outbox (фаза P6):**
- Единственный queued-класс: `DeliverWebhook` — **без `$tries`/`$timeout`/`$backoff`**, идемпотентен
  (no-op на terminal), ретрай владеет relay-sweep, НЕ очередь (`packages/core/src/Jobs/Webhooks/DeliverWebhook.php:45,59-61,220-225`).
- Transactional-outbox есть целиком: `OutboxRecorder::stage()` идемпотентен по `event_id`;
  `OutboxRelay::process(limit=100)` атомарный claim + `lockForUpdate` (`OutboxRelay.php:38,57-79`);
  **единственный владелец ретрая** — `WebhookRetryDispatcher` (`:31`, docblock §2.11/F-P1.7-05).
- ⚠ `WebhookRetryDispatcher::claimDue()` — **БЕЗ limit**: лочит и пере-армит ВСЕ due-строки в одной
  транзакции → unbounded lock-set при большом backlog (`WebhookRetryDispatcher.php:59-73`).
- ⚠ `OutboxRelay::subscribedEndpoints()` грузит ВСЕ активные endpoint'ы и фильтрует JSON `events` **в
  PHP** — O(endpoints) на каждую outbox-строку, без индекса на подписку (`OutboxRelay.php:100-110`).

**Collab (фаза P5/P7):**
- `CollaborationSessionService::issue()` — read-only эвалюатор, не персистит; Reverb = имя приватного
  канала (gated channel-auth), Hocuspocus = короткоживущий HS256 JWT, socket fan-out на внешнем Node-хабе
  (`packages/documents/src/Collaboration/*`). **Нет cap/rate-limit на issuance сессий** (в отличие от
  upload-сессий). Comment-события — `ShouldBroadcast` → broadcast-queue + Reverb fan-out на каждый коммент.

**Наблюдаемость (фаза P8) — стартовая точка = ноль:**
- НЕТ Horizon, НЕТ spatie/laravel-health, НЕТ `/up`, НЕТ метрик (Prometheus/StatsD), НЕТ трейсинга
  (OpenTelemetry/Telescope), НЕТ таймингов HTTP/GC/sweep/collab. Есть только `Log::warning` на
  best-effort сбоях и счётчики, печатаемые в консоль команд. Значит P8 — это **создание seam'ов**, не
  подключение дашборда к существующему.

### 1.3 Индексы горячих таблиц (опора для фазы P3)

Все — из `packages/core/src/Schema/PanelTablesBuilder.php` (SSOT), физические имена `v_*`.

| Таблица | Индексы (кратко) |
|:--|:--|
| v_nodes | `panel`, `kind`, `mime_type`; composite `(storage_id,parent_id,position)`, `(storage_id,kind,trashed_at)`; partial-UNIQUE `(parent_id, lower(name)) WHERE not-deleted/not-trashed`; partial `trashed_at WHERE trashed_at IS NOT NULL` |
| v_blobs | `size_bytes`; UNIQUE dedup `(sha256 \| tenant_id,sha256 \| storage_id,sha256)`; UNIQUE `storage_key`; composite gc `(status,ref_count,delete_after_at)` |
| v_upload_sessions | UNIQUE `upload_id`; `storage_id`; **sweeper composite `(status,expires_at)`** |
| v_upload_parts | composite PK `(upload_session_id, part_no)` (re-upload replace) |
| v_file_versions | `content_sha256`, `status`; UNIQUE `(file_id,version_no)`; composite `(file_id,status)` |
| v_activity | `verb`; feed-composites `(actor_*,created_at)`/`(target_*,created_at)`/`(verb,created_at)`/`(storage_id,created_at)`; `created_at`; **GIN `context jsonb_path_ops`** (pg-only). Write-hottest. |
| v_outbox | UNIQUE `event_id`; drain `(status,available_at)`; prune |
| v_webhook_deliveries | retry `(status,next_retry_at)`; prune |

Тюнинг горячих индексов уже был: `2026_07_11_000001_optimize_hot_path_indexes.php` (добавил
`trashed_at` partial, снял prefix-дубли). Фаза P3 проверяет их **под реальными объёмами**, а не на пустой БД.

---

## 2. Execution Rules

- Один item за раз; статус — в плане ДО завершения работы; 6 канонических статусов (`plan-protocol`).
- **Дисциплина D-LOAD-1 — числа раньше сценариев.** Ни один нагрузочный прогон не пишется, пока фаза P1
  не зафиксировала SLO (целевые p95/RPS/N/размер). Прогон без SLO меряет «работает/не падает», а не
  «держит ли SLO» — это шум. P1 — жёсткий гейт для P2–P7.
- **D-LOAD-2 — измеряем на production-подобии, не на dev-ноуте.** Тот же минор Postgres 16, тот же режим
  PgBouncer, реальный S3 (или MinIO с throttle), объёмы данных на порядок ближе к целевым (§ фаза P3/P5).
- **D-LOAD-3 — холодные и грязные состояния.** Замер деградации (P7) и sweeper/GC (P5/P6) — на
  предзаполненных «грязных» БД (миллион просроченных сессий, миллион orphan-блобов), а не на чистой.
- **D-LOAD-4 — план проектный.** До фазы P3 включительно артефакты — это спеки/профили/отчёты, НЕ
  прикладной код. Харнесс (k6-скрипты, seed-команды, chaos-скрипты) — это тест-инфраструктура, живёт вне
  `src/` пакета (`load/` или отдельный репо), пакетный `src/` трогаем только в P8 (seam'ы метрик) и при
  подтверждённых фиксах горячих путей — отдельными срезами через `/task:dev`, не в этом плане.
- **D-LOAD-5 — находку фиксируем как долг, не «на ходу».** Обнаруженный боттлнек (напр. `complete()` в
  RAM) не чинится внутри нагрузочной фазы — заводится строкой в §7 Findings-долг с severity и якорем, и
  уходит отдельным dev-срезом. Фаза нагрузки доказывает проблему, не лечит её.
  **Исключение — арифметические находки (D-LOAD-5a):** если дефект доказывается счётом, а не прогоном
  (нет `--min-chunk`-ограничения на СУММУ, а не на часть → N клиентов кладут N×5 ГиБ в RAM), нагрузочный
  прогон для доказательства не нужен — находка сразу Blocker-кандидат и сразу уходит в очередь
  dev-среза, не дожидаясь своей фазы (см. L-01).
- **D-LOAD-5b — фикс горячего пути открывается только после мутационного гейта того же пакета.**
  Файлы, которые VLT-LOAD метит на рефакторинг (сборка/сборщики, sweeper, tree-actions), сегодня несут
  выживших мутантов VLT-MUT (`vaulter-drive` P0.2 baseline, Score 48.34 %) — тесты вокруг них не ловят
  элементарные подмены (`===`→`!==`, удаление элемента массива). Переписывать поведение под тестами,
  которые не замечают инверсию условия, — тот же класс риска, из которого выбирается VLT-MUT. Срез
  открывается только когда VLT-MUT закрыл соответствующий пакет (D11).
- Внешние факты (версии/возможности инструментов) — только через RAG-провенанс (`findings/P0-rag-*`),
  не по памяти. Новый факт → сперва запись в findings, потом в план.
- `git push`/PR/релиз — только по явной просьбе владельца.

---

## 3. Routing

| Items | Model/effort · Tool Capability | Exec | Why |
|:--|:--|:--|:--|
| P1.1, P1.3 (тиры, SLO-числа) | opus/high | manual | цифры-обязательства каскадят на все фазы; ошибка тут инвалидирует программу |
| P1.2 (каталог операций) | sonnet/medium + `Explore` | plan-exec | рекогносцировка маршрутов/команд с якорями — механика, не решение; критерий полноты машинный (обход `Route::` + `Console/`) |
| P1.4 (гейт-политика) | opus/high + tool-capability `context7`/`perplexity-web` (RAG) | manual | синтаксис порогов k6 и политика baseline — внешние факты; «по памяти» = дефект (см. `findings/P1-rag-slo-ci-gate.md`) |
| P1.5 (sign-off) | человек (владелец) + sonnet/low на механическую правку статусов | plan-exec | решение не делегируется модели; исполнитель лишь готовит decision-sheet и переносит ответ в артефакты |
| **P2.1–P2.6, P2.8** (харнесс: спека, стенд, seed, сценарии, гейт, CI, DoD-прогон) | sonnet/medium | plan-exec | решения приняты в дизайне фазы (D22–D28) и спека items исчерпывающа: это **транскрипция и сборка**, а не выбор. Критерии зелёного машинные (`compose config`, `k6 inspect`, coverage-сверка 97 строк, exit 99 негативного теста) |
| **P2.7** (CV → δ, вердикт по классу B) | opus/high | manual | δ — число гейта, которое цена ошибки делает решением: заниженное пропускает регрессию, завышенное валит PR на шуме и гейт отключат (D14/D15). Дешёвая модель δ не выбирает |
| P3 (профилирование БД) | opus/high + `Explore`/`general-purpose` | manual | EXPLAIN-методология, индекс-ревизия — решения |
| P4 (память/стриминг) | opus/high | manual | архитектурный риск (RAM `complete()`), может потребовать редизайн сборки |
| P5 (конкуренция), P6 (очереди), P7 (деградация) | opus/high для дизайна сценариев + sonnet/medium для прогонов | manual (дизайн) / plan-exec (прогоны) | дизайн chaos/контеншн-профилей — решение; прогон — механика |
| P8 (наблюдаемость) | opus/high (дизайн метрик-seam) + `vaulter-slice-builder`/`php:laravel-implementer` для кода seam'ов | manual (дизайн) / plan-exec (seam'ы) | seam метрик касается публичного контракта пакета |
| Фиксы горячих путей (вне плана) | `/task:dev` + `vaulter-slice-builder` → `vaulter-test-writer` → `vaulter-reviewer` | manual | по D-LOAD-5, отдельными срезами |

---

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Разведка + RAG-провенанс + этот план | 1/1 | 🟢 Done |
| P1 | Целевые числа: явные SLO (N tus, ≤5 ГиБ, дерево, p95, RPS) · **гейт P2–P8** | 4/5 | 🟢 Done |
| P2 | Нагрузочный харнесс: стоковый k6 + стенд + seed + гейт + CI · **гейт P3–P7** · после P1 · *закрыто 8/8: 5 🟢 + P2.6/P2.7/P2.8 🟠* | 5/8 | 🟢 Done |
| P3 | Профилирование запросов: EXPLAIN горячих путей, ревизия индексов под объёмом · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P4 | Память и стриминг: бенчмарки ≤5 ГиБ post-фикс L-01 (D7/D11 — сам L-01 уже доказан, не гипотеза) · после P2, P8-минимума и dev-среза L-01 | 0/1 | ⬜ Not started |
| P5 | Конкуренция в масштабе: сотни контендеров, advisory-lock, пул, sweeper · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P6 | Семантика очередей под завалом: relay/retry/DLQ/идемпотентность · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P7 | Деградация инфраструктуры: S3-отвал, PG-failover, диск-full · после P3–P6 | 0/1 | ⬜ Not started |
| P8 | Наблюдаемость: метрики + трейсы (seam'ы с нуля) · **гейт P3–P7** по минимальному набору seam'ов | 0/1 | ⬜ Not started |

Детализация фаз — в `phases/Pn.md` (P1 развёрнута на 5 items, P2 — на 8; P3–P8 — скелеты с
блупринтами P0, разворачиваются `/task:plan-design VLT-LOAD Pn`).

Порядок: **P1 → P2 → P8-минимум → {P3, P4, P5, P6 параллельно} → P7**. P8 — не «параллельна и подпирает»,
а гейт: план сам фиксирует (D9), что без метрик P3–P7 не имеют опоры для вердикта («SLO нарушен» нечем
измерить) — значит статус обязан читаться как гейт, а не как фоновая активность. P8 стартует сразу за P2
(её собственная разработка идёт, пока пишутся SLO/харнесс), но P3–P7 не фиксируют результат до готовности
P8-минимума. Поставлена восьмой по нумерации запроса — не по порядку исполнения.

---

## 5. Decision Log

| ID | Решение | Основание |
|:--|:--|:--|
| D1 | **VLT-LOAD — отдельный план, второй в `ACTIVE.md`.** Ось нагрузки ортогональна оси корректности (VLT-MUT); не фаза и не замена. | §1.1 · RAG:— (внутреннее решение об осях планов, внешних фактов не несёт) |
| D2 | **Инструмент нагрузки — k6** (дефолт). Locust — резерв для Python/boto3-SigV4-гимнастики. Gatling отклонён: слаб на multi-GB стриминге и resumable-протоколах. | **RAG:✅ 2026-07-13** — RAG-1 (`findings/P0-rag-load-tooling.md`: omeronal/ranorex/testguild 2026). Alternatives: Locust — резерв (Python/boto3-SigV4); Gatling отклонён. |
| D3 | **Стриминг тела в k6 — через `xk6-fasthttp` (кастомный бинарник), НЕ стоковый k6.** Core-k6 грузит файл целиком в RAM (`open()`/`SharedArray`) → OOM генератора на 5 ГиБ × сотни VU. Экспериментальный streams-API — JS-уровень, не HTTP-тело. Для tus допустим и chunk-на-уровне-сценария. | **RAG:✅ 2026-07-13** — RAG-2 (grafana/k6 issues #2976/#2974, extension-registry) |
| D4 | **Метрики k6 — `xk6-output-prometheus-remote`**, в тот же кастомный бинарник; распределённо — **k6 Operator** (образ с бинарником в `TestRun.spec.image`). | **RAG:✅ 2026-07-13** — RAG-3 |
| D5 | **Профилирование БД:** `pg_stat_statements` (reset per run) + `EXPLAIN(ANALYZE,BUFFERS,VERBOSE)` + **HypoPG** для гипотез-индексов; lock-контеншн — `pg_blocking_pids()`-запрос + `log_lock_waits`+`deadlock_timeout=1s`; пул — PgBouncer transaction-mode. | **RAG:✅ 2026-07-13** — RAG-4 (oneuptime/yonk/monpg/vela 2026) |
| D6 | **Для деревьев проверить порог CTE-vs-ltree.** Recursive-CTE держим (cycle-guard + `withMaxDepth(64)` уже есть), но на глубоких/широких деревьях замерить, где `candidateNodeIds()` (N-CTE per grant) и `MoveNodeAction` (O(subtree) в PHP) ломают SLO; ltree/materialized-path — кандидат-долг, если CTE не тянет. | §1.2 + **RAG:✅ 2026-07-13** — RAG-4 («для read-hot иерархий ltree/materialized-path обгоняют CTE: traversal → один index range scan») |
| D7 | **`S3MultipartHandler::complete()` (весь файл в RAM) — это НЕ гипотеза P4, это доказанный Blocker-кандидат уже сейчас (D-LOAD-5a).** Механизм прямой и проверяется арифметикой, не прогоном: `complete()` конкатенирует все парты в одну PHP-строку (`packages/drive/src/Uploads/S3MultipartHandler.php:152-188`), `max_chunk_bytes` (64 MiB) ограничивает ОДИН парт, а не сумму (`readPartWithinLimit()` :230-247) — клиент, объявивший честную 5-ГиБ multipart-загрузку через публичный API, кладёт FPM-воркер по памяти без всякой конкуренции. Это вектор исчерпания памяти, доступный любому пользователю с правом на загрузку, а не только сценарий деградации под нагрузкой. Заведено в §7 как L-01 (Blocker-кандидат, статус «подтверждено», НЕ «подтвердить»). P4 не доказывает эту находку — P4 меряет УЖЕ переписанную стриминговую сборку post-фикс (после того как срез откроется по D11). | §1.2 (S3MultipartHandler.php:152-188), D-LOAD-5a · **RAG:—** — вердикт выведен чтением кода и арифметикой (внешний источник не требуется и не был бы доказательством) |
| D8 | **Unbounded-scan'ы — первоклассные цели P5/P6.** `SweepUploadsCommand` `->get()`, GC `->cursor()` без batch-cap, `WebhookRetryDispatcher::claimDue()` без limit, `OutboxRelay::subscribedEndpoints` O(endpoints)-в-PHP — измерить деградацию на «грязных» объёмах, завести долг на батчинг/чекпоинты. | §1.2 · **RAG:—** — факты из кода (якоря `file:line`), внешней верификации не требуют |
| D9 | **Наблюдаемость строим с нуля (P8), и P8 — ГЕЙТ для P3–P7, не параллельная фаза.** OpenTelemetry PHP SDK → OTLP Collector → Prometheus/Tempo; метрики очереди/GC/sweep/upload/collab и тайминги горячих путей — seam'ы, которых в пакете нет. Без базового набора метрик прогоны P3–P7 не имеют опоры для вердикта («SLO нарушен» нечем измерить) — P8 обязана поставить минимальный набор seam'ов ДО того, как P3–P7 фиксируют результат, а не просто «стартовать сразу за P2» на своих рельсах. **Уточнено P1 (D16):** сам список P8-минимума выводится не в P8, а в item'е P1.4 — из колонки «Prometheus-метрика» таблицы `slo-gates.md`. | §1.2 + **RAG:✅ 2026-07-13** — RAG-5 (OTel PHP SDK → Collector → Prometheus/Tempo; метрики очереди) |
| D10 | **Замер — на production-подобии и «грязных» состояниях** (D-LOAD-2/3): тот же Postgres-минор, PgBouncer-режим, реальный S3/MinIO-throttle, предзаполнение (млн сессий/блобов, глубокое дерево). | **RAG:✅ 2026-07-13** — RAG-4 (методология: production-подобный ворклоад, свип 0.5×…20–50× до насыщения) |
| D11 | **Фикс горячего пути (dev-срез по L-01/L-02/L-06 и т.п.) открывается только после того, как VLT-MUT закрыл `vaulter-drive` до Score ≥ 95.** Факт на сегодня (VLT-MUT P0.2 baseline, холодный кэш): `S3MultipartHandler.php` — **38** выживших мутантов, `SweepUploadsCommand.php` — **17**, `MoveNodeAction.php` — **4** (`plans/VLT-MUT/findings/P0.2-drive-escaped.txt`). Рефакторинг под тестами, которые не ловят инверсию условия или пропажу элемента массива, не доказывает, что переписанное поведение корректно — только то, что оно не упало. Порядок: VLT-MUT P2 (`vaulter-drive` → ≥95) закрывается **до** любого dev-среза из §7 по drive-файлам. | D-LOAD-5b, `plans/VLT-MUT/plan.md` P2, `plans/VLT-MUT/findings/P0.2-baseline-drive.md` · **RAG:—** — числа выживших мутантов из собственного baseline-прогона репо |
| D12 | **SLO — tiered: три эталонных профиля развёртывания S/M/L**, а не один. Vaulter — пакет, а не приложение: прод-потребителя с известной нагрузкой нет (интеграция с FlexCRM — host-задача, подтверждено памятью проекта). Хост при внедрении выбирает тир; SLO пакета остаются переносимыми. Тиры фиксирует item P1.1, числа — P1.3. | Решение владельца 2026-07-14 · **RAG:—** (не внешний факт). Alternatives: (а) один эталонный профиль — отвергнуто: при внедрении в другой хост числа пришлось бы изобретать заново; (б) вывести из FlexCRM — отвергнуто: данных нет, фаза бы заблокировалась на чужой стороне. |
| D13 | **Эталонный «железный конверт» — docker-стенд в `load/`, и «железо» задаётся `cpuset`-пиннингом, а не `--cpus`-квотой.** CFS-квота при исчерпании бюджета паркует процесс до конца периода → пилообразные всплески латентности, перцентили невоспроизводимы. Память — `mem_limit` == `memswap_limit` (swap off); `shared_buffers`/`work_mem` PG — от cgroup-лимита, не от RAM хоста. | Решение владельца 2026-07-14 (docker-стенд) + **RAG:✅ 2026-07-14** (`findings/P1-rag-slo-ci-gate.md` RAG-7: docs.docker resource_constraints — квота→throttling, cpuset→гладкая латентность). Alternatives: облачный стенд — отвергнуто владельцем: не воспроизводим в CI, шум соседей, платно. |
| D14 | **SLO машинно-проверяемы (CI-гейт на PR), и гейт — ДВУХКЛАССОВЫЙ.** Класс A (шумоустойчивый, абсолютный, блокирующий): error-rate, checks-rate, throughput при фиксированной конкуренции, потолок пиковой памяти, счётчики unbounded-scan. Класс B (латентность p95/p99): абсолютный порог — только широкий guardrail (~150 % цели), острый гейт — **относительная регрессия к baseline** (медиана из N=3, CV-guard). Один класс на всё → флапающий гейт, который отключат. | Решение владельца 2026-07-14 (SLO = CI-гейт) + **RAG:✅ 2026-07-14** (RAG-6: «you generally should not gate on absolute latencies from noisy shared GitHub Actions runners»; baseline — версионированный артефакт; медиана-из-3; `checks` сами не валят CI; `abortOnFail`+`delayAbortEval`). |
| D15 | **Латентный класс B стартует как advisory, а не блокирующий** — в репо нет dedicated perf-раннера (`.github/workflows/`: 26× `runs-on: ubuntu-latest`, self-hosted нет), и SUT крутится на том же общем раннере, что и генератор. Блокирующим класс B становится при появлении perf-раннера ЛИБО при доказанном CV < 10 % по 5 прогонам (меряется в P2). Авторитетные латентные числа снимаются на контролируемом lab-box. Вопрос владельцу — `open-questions.md` Q1. | **RAG:✅ 2026-07-14** (RAG-6: смягчающая развилка «SUT в контролируемом окружении, раннер только генерирует» у vaulter НЕ работает — оба на одном раннере). Alternatives: (а) гейтить абсолютный p95 всё равно — отвергнуто: флап → `continue-on-error` через месяц, декоративный гейт; (б) ждать perf-раннера и не делать гейт вовсе — отвергнуто: класс A шумоустойчив и ловит катастрофу уже сейчас. |
| D16 | **Каждый SLO несёт ID (`SLO-nn`) и машинное выражение порога** (k6-метрика + тег + threshold) **и имя Prometheus-метрики.** Следствие: список **P8-минимума выводится в P1.4** из этой таблицы, а не изобретается фазой P8 — иначе P8 инструментирует то, что ей удобно, и вердикт «SLO нарушен» опять не на чем построить (ровно противоречие, которое закрывал D9). | **RAG:✅ 2026-07-14** (RAG-6: тегированные пороги `http_req_duration{scenario:x}`, exit code 99, `checks: ['rate>0.99']` обязателен, `abortOnFail`+`delayAbortEval`). |
| D18 | **Гейт нагрузки — две плоскости оценки.** `k6-thresholds` (клиентские метрики, exit 99) + `perf-assert` (серверные RSS/батч/lock-set/backlog, PromQL). Серверные обязательства класса A порогами k6 невыразимы (RAG-8b) — без второй плоскости половина класса A была бы гейтом, который никто не вычисляет. | `slo-gates.md` §1/§3 (P1.4) · формализовано в P1.5 (было «D18-кандидат» в Pending Work P1.4) · **RAG:✅ 2026-07-14** (`findings/P1.4-rag-k6-threshold-syntax.md` RAG-8b) |
| D19 | **Sign-off владельца (P1.5, 2026-07-14): все 81 строка `slo.md` — `approved` без правки чисел.** Владелец принял предложенные исполнителем порядки величин («золотая середина») целиком — латентные бюджеты интерактива p95 60–250 мс, лаг фида активности ≤ 1 с, сквозной webhook-лаг p95 ≤ 10 с, граница single-shot ≤ 32 MiB, поддерживаемый в README тир **M** — а также δ = 10 % (`slo-gates.md` §4.3) как рабочую гипотезу до измерения CV в P2. Приоритет сейчас — стабильный публичный API, не самая жёсткая латентность; всё, что жёстче предложенного, — не отклонено, а перенесено в roadmap-список (`slo-signoff.md` §3), пересмотр — после того, как P2 измерит CV. | Решение владельца 2026-07-14 (`slo-signoff.md` §1/§3) · **RAG:—** (бизнес-решение, не внешний факт) |
| D20 | **Q1 разрешён: dedicated/self-hosted perf-раннер пока не выделяется.** Класс B (латентность) остаётся **advisory** (пишет baseline, не валит PR) до появления perf-раннера ИЛИ доказанного CV < 10 % по 5 прогонам в P2 — оба условия перехода в блокирующий (`slo-gates.md` §4.4) не меняются этим решением, просто первое условие сегодня не выполнено. | Решение владельца 2026-07-14 (`slo-signoff.md` §2, `open-questions.md` Q1) · **RAG:—** (организационное решение) |
| D21 | **Q3 разрешён: CI-профиль `S0` принят** как замена тиру S в `smoke-perf`/`regression` — усечённый (без cpuset, объёмы ≈ 1/50 S, файл ≤ 256 МиБ, только класс A) сторож, не обещание развёртывания. Снимается сам по себе при появлении perf-раннера (смыкается с D20/Q1). | Решение владельца 2026-07-14 (`slo-signoff.md` §2, `open-questions.md` Q3) · **RAG:—** (решение о CI-профиле, факты RAG-7 уже учтены в D17) |
| D22 (**supersedes D3**) | **Кастомный k6-бинарник отменён: харнесс — СТОКОВЫЙ k6.** `xk6-fasthttp` **не эмитит** `http_req_duration`, `http_req_failed`, `http_req_*`, `data_sent`, `data_received` (README расширения перечисляет их как «потерянные» из-за ограничений fasthttp) — а на этих метриках стоит ВЕСЬ гейт P1.4: сквозные G-1/G-2 (`http_req_failed{class:…}`), агрегатный throughput (`data_sent`/`data_received`) и все строки класса B §3.1–§3.2 `slo-gates.md`. Сборка на fasthttp обнулила бы половину гейта; вдобавок PATCH (на котором целиком стоит tus, OP-02) в его API не документирован, последний релиз — 09.2024. **И главное: стриминг тела vaulter'у не нужен вовсе** — ни один эндпоинт не принимает multi-GB тело одним запросом (tus-чанк 8 MiB · S3-часть ≤ 64 MiB · single-shot ≤ 32 MiB по D19 · download — тело ОТВЕТА, снимается `discardResponseBodies`). | **RAG:✅ 2026-07-14** (`findings/P2-rag-k6-harness.md` RAG-9/RAG-11/RAG-14). Alternatives: (а) xk6-fasthttp — отвергнут (потеря метрик = потеря гейта); (б) Locust — не нужен: S3-multipart у vaulter реализован **своими** HTTP-роутами (`S3MultipartController.php:56,85`), SigV4 подписывает сервер, а не клиент ⇒ аргумент §8.1 «Locust дешевле для boto3/SigV4» к пакету неприменим |
| D23 (уточняет `reference-env.md` §1.5) | **Фикстуры генерируются в памяти, а не читаются с диска.** Общий `ArrayBuffer` (8 MiB — tus-чанк, 64 MiB — S3-часть) создаётся один раз в init-контексте; уникальность содержимого файла даёт 16-байтовый **salt в первом чанке сессии** (без него CAS-дедуп схлопнет все загрузки в один блоб — `BlobStore::commit()` ищет по dedup-ключу — и сценарий будет мерить дедуп вместо записи). Требование §1.5 «фикстуры на диске, не через `open()`» ловушку RAM **не снимает**: `k6/experimental/fs` держит **одну полную копию файла в памяти**, общую для VU ⇒ 5-ГиБ фикстура = 5 ГиБ RSS генератора при лимите 2/4/8 ГиБ. Следствие: 5-ГиБ файла на диске стенда не существует, требование «диск ≥ 20 ГиБ под фикстуры» снимается. | **RAG:✅ 2026-07-14** (RAG-10: `fs` — одна общая копия в RAM; `discardResponseBodies` — тело ответа не удерживается) |
| D24 (**supersedes D4**) | **Метрики k6 — встроенный выход `--out experimental-prometheus-rw`**, а не расширение `xk6-output-prometheus-remote`: оно **влито в ядро** k6 с версии v0.42. Кастомный бинарник ради вывода не собирается. k6-Operator остаётся вариантом только для распределённого прогона (в P2 не нужен). | **RAG:✅ 2026-07-14** (RAG-12) |
| D25 (уточняет `reference-env.md` §3) | **Авторитетный тир прогонов P2 — S**, не M. Lab-box (i7-14700K) даёт 8 P-ядер + 12 E-ядер: бенч-стенд S (7 физических ядер) кладётся на P-ядра честно, а M (12 однородных ядер, ~28 ГиБ из 31) **однородного cpuset'а не получает** — L-2 (`reference-env.md` §5) нарушается, латентность становится невоспроизводимой. Следствия: M гоняется только как **класс A** с явной пометкой «латентность недостоверна» (как S0), L — экстраполяция, а **публично обещанный README-тир M (D19/Q2) остаётся не подтверждённым прогоном** — это названо в `open-questions.md` **Q4** и в Open risks handoff'а. | Решение владельца 2026-07-14 («приоритет — стабильный API и задел; жёсткие требования пока не ставим, ужесточение — в roadmap») + [из железа] `lscpu -e` · **RAG:—** (топология конкретного хоста, внешним источником не верифицируется). Alternatives: (а) ужать тир M под железо — отвергнуто (подмена предмета замера, ровно ошибка, которую D17 уже запретил); (б) гонять M на гетерогенных ядрах и молчать о дисперсии — отвергнуто (анти-самообман) |
| D26 | **`load/` живёт в ЭТОМ репозитории** (не отдельным репо): гейт `smoke-perf` обязан запускаться на PR этого репо, а baseline-артефакт — проходить code review в том же PR (`slo-gates.md` §4.1). Пакетный `src/` при этом не трогается (D-LOAD-4) — единственное исключение вне `load/`: `require-dev` (S3-драйвер) и `.github/workflows/load.yml`. | D-LOAD-4 + `slo-gates.md` §4.1 · **RAG:—** (внутреннее решение о раскладке репо) |
| D27 | **Push ветки `load/harness` в `origin` + `workflow_dispatch` разрешены владельцем** (2026-07-14) — единственное исключение из правила «push только по явной просьбе» (§2). Без него item P2.7 (CV на реальном `ubuntu-latest`) неисполним, δ остаётся гипотезой, а класс B — advisory навсегда. PR/релиз — по-прежнему по отдельной просьбе. | Решение владельца 2026-07-14 · **RAG:—** |
| D28 | **SUT — nginx + php-fpm, а не `vendor/bin/testbench serve`.** `testbench serve` = `php artisan serve` = однопоточный dev-сервер: на нём `pm.max_children` (8/32/96) и вся раскладка FPM-пула (`slo.md` §0.1 — интерактив/upload/download делят один пул) физически не воспроизводятся, а именно она задаёт конкуренцию всех SLO. Существующий `e2e/docker-compose.yml` переиспользуется как **образец провайдера хоста** (`packages/core/tests/E2E/E2eServiceProvider.php`), а не как SUT. | [из кода] `e2e/docker/Dockerfile.laravel` (CMD `testbench serve`), `slo.md` §0.1, `reference-env.md` §1.6 · **RAG:—** (свойство `artisan serve` — факт фреймворка, не спорный внешний вердикт) |
| D29 (закрывает δ-гипотезу D19 и условие 2 §4.4) | **Класс B остаётся advisory; δ = 10 % не подтверждён и на S0 опровергнут.** Пять прогонов `smoke-perf` на реальном `ubuntu-latest` (один commit `e7ddf9a`, один `dataset_id`, разнесены по времени) дали CV латентности **26–56 %** (`http_req_duration` p95 — 34.6 %, худшая `iteration_duration` p95 — 56.3 %) при CV шумоустойчивых метрик **0.4–4 %** (`checks` rate, `iterations` rate). Условие 2 §4.4 (CV < 10 %) не просто «не проверено» — оно **опровергнуто измерением**; условие 1 закрыто владельцем (D20). δ **не повышается** под шум (пришлось бы ставить +70…110 % — это декоративный гейт, запрещённый D14/D15): δ остаётся числом профиля `lab` и получает измерительное задание в P2.8. Дополнительно вскрыт **независимый блокер**: латентные сабметрики в профилях `smoke-perf`/`regression` не эмитятся вовсе (`thresholds.js` объявляет латентные пороги только в `lab`, а k6 материализует сабметрику лишь по объявленному порогу) ⇒ все **54 строки класса B** — `not-checkable`, сравнивать с baseline нечего. Отсюда §4.4 получил **предусловие**, которого не было в дизайне P1.4. | `findings/P2.7-cv-runner.md` (P2.7; 5 прогонов, run-id в таблице), `slo-gates.md` §4.3/§4.4 · **RAG:—** — собственное измерение на раннере (RAG-6 лишь предсказывал этот исход, доказательство — числа) |
| D30 (advance над D29; закрывает измерительное задание §4.3) | **На lab-box'е (тир S, cpuset) δ = 10 % ПОДТВЕРЖДЁН защитимым; L-01 доказан прогоном.** Пять прогонов `smoke-perf`-обвязки на cpuset-стенде (governor=performance, turbo off, swap off, тихий хост; **тот же датасет `vlt-load-414fae92477d07f8`, что и P2.7** — изолирована ровно переменная окружения) дали CV **плотной** латентности `http_req_duration`/`http_req_waiting`/`iteration_duration` p95 = **2.0–2.7 %** — в 13–28× тише shared-раннера P2.7 (34.6–56.3 %) и **≪ 10 %**. Это ровно число, которое D29 оставил «числом профиля `lab`, подлежащим измерению в P2.8». Выброс `ttfb_ms` p95=101.5 % — артефакт разрежённого кастомного Trend (<30 сэмплов/окно), не шум окружения. **δ=10 % на `lab` защитим по оси шума**; НО класс B ещё не гейтит в `lab` из-за плумбинг-блокеров (per-scenario сабметрики не материализуются: `thresholdsFor()` не объявляет латентных порогов + сценарии тегируют `scenario`=именем файла, не слагом §3.1 — F-P2.8-3), не из-за δ. Параллельно **L-01 доказан прогоном** (было арифметикой D7): s3-`complete()` на 500 MiB → HTTP 500, `Allowed memory size of 536870912 exhausted at S3MultipartHandler.php:165`; тот же файл через tus → 201 при anon 46 MiB (стримит). | `findings/P2.8-baseline-S.md` (P2.8; 5 прогонов, RSS-тренд, лог fatal'а), `slo-gates.md` §4.3/§4.4 · **RAG:—** — собственное измерение на cpuset-стенде (RAG-6 предсказывал, что контролируемое окружение снимет шум; доказательство — числа) |
| D17 (подтверждён D21, Q3 разрешён) | **Тир S не помещается в `ubuntu-latest` ⇒ CI гоняет усечённый профиль `S0`, а не тир S.** Бенч-стенд S = 7 ядер (SUT 4 + генератор 2 + резерв хоста 1) и ~9,3 ГиБ; раннер даёт 4 vCPU / 16 ГиБ / ~14 ГиБ диска на общем хосте — непересекающиеся cpuset'ы app/PG/MinIO/генератора (инвариант E-4) физически не строятся. Числа тира S **не ужимались** под возможности CI (это была бы подмена предмета замера): вводится отдельный профиль **S0** — без cpuset, объёмы ≈ 1/50 от S, максимальный файл 256 МиБ (5-ГиБ фикстура + её копия в MinIO не помещаются на диск раннера), **только класс A**. S0 — не обещание развёртывания, а шумоустойчивый сторож. Смыкается с D15/Q1: с появлением perf-раннера S0 не нужен. | `reference-env.md` §4.1 (P1.1) · **RAG:✅ 2026-07-14** (RAG-6: латентность с shared-раннера недостоверна; RAG-7: cpuset-конверт требует свободных ядер) · **решение владельца — `open-questions.md` Q3** |

---

## 6. Update Log

Append-only. «Что» ≤ 2 строк; детали закрытых items — в `phases/Pn.md` (Completion Notes).

| Дата | Кто | Что |
|:--|:--|:--|
| 2026-07-13 | opus/high (`/task:design`) | P0 закрыт: рекогносцировка кодовой базы + RAG-провенанс инструментов (`findings/P0-rag-load-tooling.md`) + мастер-план VLT-LOAD (D1–D11). Плану выделена вторая строка в `plans/ACTIVE.md` (ось нагрузки ⊥ оси корректности VLT-MUT). |
| 2026-07-14 | opus/high (`/task:plan-design VLT-LOAD P1`) | P1 спроектирована: 5 items (`phases/P1.md`), DoR пройден. Новые решения D12–D16 (tiered SLO; docker-конверт на cpuset; двухклассовый CI-гейт; класс B advisory; SLO-ID ⇒ P8-минимум из P1.4). RAG — `findings/P1-rag-slo-ci-gate.md`. |
| 2026-07-14 | opus/high (`/task:plan-design VLT-LOAD P1`) | Раскладка приведена к протоколу 2.0: блупринты фаз уехали в `phases/Pn.md` (P2–P8 — скелеты), заведены `phases/P0.md`, `handoff.md`, `open-questions.md`; §6 стал Update Log, доска — в канон. |
| 2026-07-14 | opus/high (`/task:plan-exec VLT-LOAD P1.1`) | P1.1 закрыт (🟠): `reference-env.md` — тиры S/M/L (cpuset-конверт + объёмы `v_*`) + профиль S0; конфликт «S не влезает в `ubuntu-latest`» → D17-кандидат + Q3 — детали см. phases/P1.md P1.1 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P1.2`) | P1.2 закрыт (🟢): `operations-catalog.md` — 47 OP-nn (56 маршрутов обойдено, 38 в каталоге, 18 исключены с причиной) + связка с L-01…L-06/L-08 — детали см. phases/P1.md P1.2 Completion Notes. |
| 2026-07-14 | opus/high (`/task:plan-exec VLT-LOAD P1.3`) | P1.3 закрыт (🟢): `slo.md` — 81 строка `SLO-nn` (27 групп × S/M/L), полное покрытие 47 OP; бюджет FPM-пула, error-budget (409/429 — не ошибки), §5 «не выполняется сегодня» (SLO-46…48 / L-01) — детали см. phases/P1.md P1.3 Completion Notes. |
| 2026-07-14 | opus/high (`/task:plan-exec VLT-LOAD P1.4`) | P1.4 закрыт (🟢): `slo-gates.md` — 97 гейтов на 81 SLO (A блокирующий / B advisory), две плоскости оценки (k6 + perf-assert; D18-кандидат), P8-минимум 37 метрик — детали см. phases/P1.md P1.4 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P1.5`) | P1.5 закрыт (🟢), **фаза P1 закрыта (🟢, 5/5)**: `slo-signoff.md` — владелец принял 81/81 строку `slo.md` «золотой серединой» без правки чисел + Q1/Q2/Q3 разрешены (D19–D21) + D18 формализован — детали см. phases/P1.md P1.5 Completion Notes. |
| 2026-07-14 | opus/high (`/task:plan-design VLT-LOAD P2`) | P2 спроектирована: 8 items (`phases/P2.md`), DoR пройден. RAG опроверг блупринт — D22 (стоковый k6, supersedes D3), D24 (supersedes D4), D23, D25 (тир S; Q4), D26–D28 — детали см. `findings/P2-rag-k6-harness.md` и §5. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.1`) | P2.1 закрыт (🟢): `harness.md` — инструмент/стенд/фикстуры/executor'ы/контракт метрик/профили, транскрипция D22–D28 без новых чисел — детали см. phases/P2.md P2.1 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.2`) | P2.2 закрыт (🟢): `load/` — compose (7 сервисов, cpuset/mem_limit/memswap_limit-конверт), env-профили S0/S/M, SUT-образ nginx+php-fpm, `LoadServiceProvider`, скрипты, README; провалидировано живыми docker-прогонами (tier S0 и S) — детали см. phases/P2.md P2.2 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.3`) | P2.3 закрыт (🟢): `load:seed`/`load:activity-write` — объёмный+«грязный» конверт, профили S0/S/M/L, снапшот/restore; провалидировано живым прогоном tier S0 — детали см. phases/P2.md P2.3 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.4`) | P2.4 закрыт (🟢): `load/k6/` — библиотека + 7 сценариев + `smoke.js`, `load/scripts/run.sh` (новый); провалидировано живым прогоном tier S0 (100% checks, DB/MinIO пост-условия) — детали см. phases/P2.md P2.4 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.5`) | P2.5 закрыт (🟢): `load/gates/{thresholds.js,perf-assert.py,coverage.py}` + первый baseline; живой прогон tier S0 (coverage 96/96, exit 0/99) — детали см. phases/P2.md P2.5 Completion Notes. |
| 2026-07-14 | sonnet/medium (`/task:plan-exec VLT-LOAD P2.6`) | P2.6 закрыт (🟠): `load.yml` (smoke-perf/regression/baseline-guard) + `run.sh` gated-профили; гейт живьём подтверждён, но красный по честным S0-числам (не почищено — вне Scope) — детали см. phases/P2.md P2.6 Completion Notes. |
| 2026-07-14 | opus/high (manual, §3 Routing) | P2.7 закрыт (🟠): 5 прогонов на `ubuntu-latest` → CV латентности 26–56 % ⇒ **D29** (класс B — advisory, δ не подтверждён). Починены 6 дефектов лейна, невидимых локально — детали см. phases/P2.md P2.7 Completion Notes и `findings/P2.7-cv-runner.md`. |
| 2026-07-15 | opus/high (manual, §3 Routing) | **P2.8 закрыт (🟠), фаза P2 закрыта (🟢, 8/8)** → **D30**: прогон на cpuset-стенде тира S (lab-box). tus стримит (anon RSS плоский 40–48 MiB); CV плотной латентности 2.0–2.7 % ⇒ δ=10 % на `lab` защитим; L-01 доказан прогоном. Детали — phases/P2.md P2.8 Completion Notes + `findings/P2.8-baseline-S.md`. |

---

## 7. Findings-долг (заполняется по ходу)

Боттлнеки, найденные фазами, но НЕ чинимые внутри них (D-LOAD-5). Каждый → отдельный dev-срез.
Колонка «VLT-MUT-долг» — выжившие мутанты в этом файле (`P0.2-drive-escaped.txt`, холодный кэш,
2026-07-13) — держит D11: dev-срез не открывается, пока drive не закрыт до ≥95.

| ID | Severity | Находка | Якорь | VLT-MUT-долг | Фаза-источник | Статус |
|:--|:--|:--|:--|--:|:--|:--|
| L-01 | **Blocker-кандидат** (арифметика D7 → **доказано ПРОГОНОМ P2.8**) | `S3MultipartHandler::complete()` собирает весь файл в RAM; `max_chunk_bytes` ограничивает один парт, не сумму — любой пользователь с правом на загрузку объявляет честную 5-ГиБ multipart-загрузку и кладёт FPM-воркер по памяти без конкуренции. **P2.8: s3-`complete()` на 500 MiB → HTTP 500, `Allowed memory size of 536870912 exhausted at S3MultipartHandler.php:165` (тот же файл через tus → 201, anon 46 MiB)** | `packages/drive/src/Uploads/S3MultipartHandler.php:152-188` (fatal на :165) | 38 | доказано прогоном P2.8 (`findings/P2.8-baseline-S.md` §3); P4 меряет пост-фикс | 🟡 **готово к dev-срезу — ждёт только D11 (VLT-MUT drive ≥95)** |
| L-02 | (гипотеза) Med | `SweepUploadsCommand ->get()` без limit/chunk на млн сессий | `packages/drive/src/Console/SweepUploadsCommand.php:37` | 17 | P5 | ⚪ подтвердить в P5; фикс — после D11 |
| L-03 | (гипотеза) Med | GC `->cursor()` без batch-cap/чекпоинта на млн orphan-блобов | `packages/media/src/Cas/BlobGarbageCollector.php:293-298` | — (пакет media, не drive; VLT-MUT баланс media вне текущего P0.2) | P6/P5 | ⚪ подтвердить |
| L-04 | (гипотеза) Med | `WebhookRetryDispatcher::claimDue()` без limit — unbounded lock-set | `packages/core/src/Webhooks/WebhookRetryDispatcher.php:59-73` | — (пакет core) | P6 | ⚪ подтвердить |
| L-05 | (гипотеза) Low-Med | `OutboxRelay::subscribedEndpoints()` O(endpoints)-в-PHP на outbox-строку | `packages/core/src/Webhooks/OutboxRelay.php:100-110` | — (пакет core) | P3/P6 | ⚪ подтвердить |
| L-06 | (гипотеза) Med | `candidateNodeIds()` — N-CTE per grant; `MoveNodeAction` O(subtree) в PHP | `NodeReadRepository.php:454-529` (core), `MoveNodeAction.php:85-100` (drive) | MoveNodeAction: 4 | P3/P5 | ⚪ подтвердить в P3/P5; фикс `MoveNodeAction` — после D11 |
| L-07 | (гипотеза) Low | CAS-лок int4-ключ (`hashtext`) — коллизии при storm дедупа | `packages/core/src/Cas/BlobLock.php:35-49` | — (пакет core) | P5 | ⚪ подтвердить |
| L-08 | (факт) Low | Sweeper/relay/GC **не зарегистрированы в расписании** пакетом — хост-долг | `SweepUploadsCommand`, `RelayOutboxCommand` docblocks | — | P8/RUNBOOK | ⚪ задокументировать |
| F-P2.8-1 | (гипотеза) Med | throughput одиночного стрима на тире S ниже пола SLO-40 (tus сквозняк ~1.9 MiB/s на 500-MiB сессии; med chunk ~22 MiB/s < 25) — боттлнек TBD (MinIO 1 ядро / per-PATCH fsync / append cost) | `TusUploadHandler`/`S3MultipartHandler` + конверт `reference-env.md` §1.4 | — | P3/P4 (замер P2.8, `findings/P2.8-baseline-S.md` §2/§6) | ⚪ подтвердить/диагностировать в P3/P4 |

**Правило D11 для этой таблицы:** «VLT-MUT-долг» > 0 в пакете `vaulter-drive` ⇒ фикс этой строки
блокирован до `plans/VLT-MUT/plan.md` P2 = 🟢 Done. Пакеты `core`/`media` блокированы аналогично своей
фазой VLT-MUT (P3/P4 там), сегодня не приоритет — L-03/L-04/L-05/L-07 живут в core/media, у них нет
собственного мутационного baseline ещё (VLT-MUT P0.2 снят только для drive).

---

## 8. Обсуждение (спорное — рецензия и рекомендация)

### 8.1 k6 vs Locust — почему k6, и когда бы взяли Locust

**За k6 (рекоменд.):** Go-рантайм тянет десятки тысяч VU при меньшей памяти; JS-скриптинг + thresholds/
scenarios идеально ложатся на длинные upload-flow; экосистема Prometheus/Grafana/k6-Operator зрелая для
CI и k8s. **Против:** нет нативного стриминга тела с диска → нужен кастомный бинарник (`xk6-fasthttp`),
это сборочный оверхед и extension-риск.
**За Locust:** Python-экосистема (boto3, SigV4) делает S3-multipart-гимнастику дешевле; per-user
state-machine естественнее для сложных resume/error-сценариев. **Против:** тяжелее по памяти на воркер
при multi-GB, обычно ниже достижимый concurrency.
**Рекомендация (первой редакции):** k6 + xk6-fasthttp; Locust в резерве под S3-SigV4-скриптинг.

**Пересмотрено при проектировании P2 (2026-07-14).** Резерв Locust отпал не по «весу воркера», а
потому что **предпосылки не было**: S3-multipart у vaulter — это его собственные HTTP-роуты
(`POST resumable-uploads/{id}/parts`, `.../complete` — `S3MultipartController.php:56,85`), SigV4
подписывает **сервер**. Клиенту нечего подписывать, значит и «Python-гимнастика» ему не нужна.

**Статус:** Resolved → **D22** (стоковый k6; Locust не нужен).

### 8.2 Стриминг в k6: кастомный бинарник vs chunk-на-сценарии

Первая редакция предлагала оба варианта: **кастомный бинарник (xk6-fasthttp)** — истинный стрим тела
с диска; **chunk-на-сценарии** — слать PATCH-ями по `chunk_size`.

**Развилка снята RAG'ом P2 (2026-07-14), причём с обеих сторон** (`findings/P2-rag-k6-harness.md`):

1. Кастомный бинарник **непригоден**: fasthttp-клиент не эмитит `http_req_*` и `data_sent`/
   `data_received` (RAG-11) — то есть отменяет метрики, на которых P1.4 построил весь гейт, и не
   документирует PATCH, без которого tus не существует.
2. Кастомный бинарник **не нужен**: у vaulter нет ни одного эндпоинта, принимающего multi-GB тело
   одним запросом (RAG-14). 5-ГиБ файл ВСЕГДА приезжает чанками — «честный 5-ГиБ single-stream»,
   ради которого затевался бинарник, не является операцией пакета.
3. «Chunk-на-сценарии» тоже был описан неточно: у него оставалась дисковая фикстура — а
   `k6/experimental/fs` держит **полную копию файла в RAM**, общую для VU (RAG-10). Ловушка RAM
   никуда не девалась, просто переезжала с `open()` на `fs`. Правильный ответ — **не иметь файла**:
   общий `ArrayBuffer` + salt в первом чанке.

**Статус:** Resolved → **D22** (стоковый k6) + **D23** (фикстуры в памяти).

### 8.5 Тир M: обещан публично (README), но померить его негде

`slo-gates.md` §4 привязывает baseline класса B к тиру **M**, а sign-off владельца (D19/Q2) объявил
**M** поддерживаемым тиром README. Проектирование P2 обнажило, что **M на доступном железе не
строится честно**: lab-box — i7-14700K (8 P-ядер + 12 E-ядер, 31 ГиБ); бенч-стенд M требует 12
**однородных** ядер и ~28 ГиБ ⇒ cpuset неизбежно захватит E-ядра (нарушение L-2 — разнородная частота
= невоспроизводимые перцентили), а RAM садится на грань.

- **(рекоменд.) Вариант A.** Авторитетный тир характеризации — **S** (7 физических P-ядер, честный
  cpuset). M гоняется только как **класс A** («латентность недостоверна», как на S0), L —
  экстраполяция. Плюсы: ни одно число не выдумано. Минусы: публичное обещание M остаётся
  **неподтверждённым** — это надо назвать в README, а не замолчать.
- **Вариант B.** Выделить/арендовать lab-box под M. Плюсы: обещание README становится проверенным.
  Минусы: деньги и время; владелец 2026-07-14 сказал «пока по минимуму, важен стабильный API и задел».
- **Вариант C.** Гонять M на гетерогенных ядрах и не оговаривать дисперсию. Отвергнут: это ровно
  анти-самообман, запрещённый Code Guidance фазы P1.

**Статус:** Resolved → **D25** (принят вариант A) · **остаток открыт** — «где брать lab-box под M» →
`open-questions.md` **Q4** (`Decision pending`), P2 не блокирует.

### 8.3 Статус P8 — «параллельна» vs «гейт» (пересмотрено)

Первая редакция плана статусила P8 как «параллельна, подпирает P3–P7». Ревью верно указало
противоречие: тот же абзац утверждал «без метрик прогоны P3–P7 слепые» — а слепой прогон не
может быть гейтом ЗА собой, значит фаза, которая делает его не-слепым, обязана быть гейтом ПЕРЕД
ним. **Решение (D9, зафиксировано):** P8 — гейт P3–P7 по минимальному набору seam'ов (латентность
горячих путей каждого SLO-класса из P1 + `/up`), не по полному закрытию фазы. Двухступенчатый DoD
(§ фаза P8) разрешает противоречие: P8-минимум блокирует старт P3–P7, полное закрытие спеки —
нет, оно донабирается по итогам P3–P7. «Параллельна» осталась неверной формулировкой первой
редакции — её замена на «гейт P3–P7» в §4/D9 это и есть исправление, а не альтернатива для выбора.

**Статус:** Resolved → **D9** (P8 — гейт P3–P7 по P8-минимуму; формулировка «параллельна» снята и из
§0 Meta, и из §4 при проектировании P2).

### 8.4 Граница «нагрузочная фаза доказывает, dev-срез чинит» (D-LOAD-5) — и её исключение

Общее правило D-LOAD-5 держится: соблазн чинить `complete()` прямо в P4 отклонён — смешение осей
(нагрузка vs реализация), раздувание фазы, потеря воспроизводимости замера «до».

**Но L-01 — не общий случай.** Ревью верно отделило находки, которые нужно ДОКАЗАТЬ прогоном
(«держит ли контеншн 500 клиентов» — нужен харнесс), от находок, которые доказываются счётом
(«сумма частей не ограничена — читай код»). Держать доказанный-арифметикой Blocker в очереди до
P4 только потому что «так положено по порядку фаз», значило бы воспроизводить ровно ту ошибку,
которую VLT-MUT уже вскрыл на mutation-гейте: методология ради методологии, а не ради результата.
**Решение:** D-LOAD-5a выводит арифметические находки из-под общего правила — они Blocker-кандидат
сразу, без дожидания своей фазы. P4 после этого не «доказывает L-01», а проверяет РЕЗУЛЬТАТ фикса.

**Второе исключение работает в обратную сторону — D-LOAD-5b/D11.** Даже доказанная находка не
открывает dev-срез немедленно, если файл несёт непогашенный VLT-MUT-долг (`S3MultipartHandler.php`
— 38 выживших мутантов). Причина та же, что и у самого VLT-MUT: тесты, которые не ловят инверсию
условия, не докажут, что переписанная сборка ведёт себя как задумано — они лишь не упадут. Значит
порядок для L-01 такой: подтверждено сейчас (Blocker-кандидат) → **ждёт** VLT-MUT `vaulter-drive`
≥95 (D11) → dev-срез фикса под уже надёжными тестами → P4 меряет результат. Два исключения не
противоречат друг другу: одно снимает ожидание СВОЕЙ фазы (P4), второе вводит ожидание ЧУЖОГО
плана (VLT-MUT) — они про разные оси задержки.

**Статус:** Resolved → **D7** (D-LOAD-5a: арифметическая находка — Blocker сразу) + **D11**
(D-LOAD-5b: dev-срез ждёт мутационный гейт drive ≥ 95).

---

## 9. Провенанс

| Источник | Где | Что дал |
|:--|:--|:--|
| RAG (perplexity-web) | `findings/P0-rag-load-tooling.md` | k6/Gatling/Locust 2026, k6-стриминг-ловушка, xk6, Postgres-профилирование, очереди/OTel/chaos |
| RAG (perplexity-web) | `findings/P2-rag-k6-harness.md` | **опровержение D3/D4**: xk6-fasthttp теряет `http_req_*`/`data_*` и не документирует PATCH; Prometheus-выход в ядре k6 с v0.42; `k6/experimental/fs` держит полную копию файла в RAM; `discardResponseBodies` снимает вопрос 5-ГиБ скачивания |
| Рекон кодовой базы | §1.2/§1.3 плана (якоря file:line) | горячие пути, индексы, память-vs-стриминг, unbounded-scan'ы, наблюдаемость=absent |
| mAInd память | scope `project:vaulter` | M0–M4b контекст (CAS, uploads, tree, collab, outbox) |

Внешние ссылки первоисточников перечислены в `findings/P0-rag-load-tooling.md` (omeronal, ranorex,
testguild, grafana/k6 issues, grafana docs, oneuptime, yonk, monpg, vela, itmarkerz, opentelemetry.io).
