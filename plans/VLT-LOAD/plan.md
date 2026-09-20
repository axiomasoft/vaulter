# План

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-LOAD |
| Short ID | PLAN1 |
| Title | Нагрузочная готовность vaulter: SLO → сценарии → профилирование → память/стриминг → конкуренция → очереди → деградация → наблюдаемость |
| Document Type | Executable Master Plan (design-first: до P3 — артефакты и решения, не код) |
| Authoring Model | Grok 6.4 для design/measurement решений; Composer 2.5 для bounded implementation; Sol для независимого review (см. §3 и `plans/RUNBOOK.md`) |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-drive, vaulter-documents, vaulter-media, vaulter-filament |
| Стек | PHP 8.4, Laravel 12/13-contracts, Postgres 16, Flysystem+S3, tus/S3-multipart, staudenmeir HasRecursiveRelationships, Laravel queues, Reverb/Hocuspocus |
| Execution Mode | phase-first: P1 (SLO) — гейт для всего; P2 (харнесс) — гейт для P3–P7; P8 (наблюдаемость) — **тоже гейт** P3–P7 по минимальному набору seam'ов (D9; формулировка «параллельна» первой редакции снята) |
| Ось | **поведение под нагрузкой**, НЕ корректность (это ось VLT-MUT). Планы не пересекаются. |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-LOAD |
| Depends on | **Проектирование** — независимо от VLT-MUT (VLT-REMED/VLT-CANON в архиве). **Исполнение фиксов** (dev-срезы горячих путей, D-LOAD-5) — зависит от VLT-MUT по файлу: срез не открывается, пока VLT-MUT не закрыл `vaulter-drive` до Score ≥ 95 (см. D11). |
| Branch | `remediation/audit-vlt` (design-фаза — коммитим только артефакты plans/; код фаз — по решению владельца) |
| Paused By | —; VLT-REL завершён и архивирован. D34 удалил ложные placeholder-items P3.1–P7.1; текущий runnable — P8.1. |
| Layout | v2 |
<!-- migration-version: 0.4.0 -->

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
- **Переходное правило Layout v2 (до P6):** закрытие пункта фиксируется записью в `journal.jsonl`, находкой или решением при необходимости и регенерацией видов; поля исхода в файле ТЗ запрещены и ловятся линтером.

## 3. Routing

P0–P2 ниже сохраняют исторический routing уже завершённой работы. Актуальные назначения открытых
пунктов — Grok 6.4 / Composer 2.5 / Sol; полная межплановая матрица и порядок находятся в
`plans/RUNBOOK.md`.

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| solo | P0.0 | implementation/medium | plan-exec | none | required: исторический route уже закрытого migration item |
| solo | P1.1 | frontier/high | manual | none | required: исторический route закрытого item; цифры каскадировали на весь план |
| solo | P1.2 | implementation/medium | plan-exec | none | required: исторический route закрытого рекона |
| solo | P1.3 | frontier/high | manual | none | required: исторический route закрытого SLO-item |
| solo | P1.4 | frontier/high | manual | none | required: исторический route с RAG для k6/baseline |
| solo | P1.5 | implementation/low | plan-exec | none | required: исторический owner sign-off уже импортирован |
| P2-historical | P2.1–P2.6 | implementation/medium | plan-exec | none | required: историческая adjacent-цепочка харнесса сохранена без перемаршрутизации |
| solo | P2.7 | frontier/high | manual | none | required: исторический route решения по δ |
| solo | P2.8 | implementation/medium | plan-exec | none | required: исторический route DoD-прогона |
| solo | P8.1 | frontier/high | manual | full | recommended: публичный SemVer-контракт, кардинальность и health-semantics; после исполнения обязателен независимый Sol `audit-design` до P8.2 |
| solo | P8.2 | implementation/high | plan-exec | full | recommended: реализация public seam только по GREEN P8.1 design review |
| solo | P8.3 | implementation/high | plan-exec | light | recommended: аддитивная инструментация 12 метрик §5.1 в трёх пакетах |
| solo | P8.4 | implementation/high | plan-exec | full | recommended: streaming/memory hot paths и L-01 требуют независимой проверки аддитивности |
| solo | P8.5 | implementation/high | plan-exec | light | recommended: аддитивная инструментация 28 фоновых метрик по замороженному маппингу |
| solo | P8.6 | implementation/high | plan-exec | light | recommended: SUT-level liveness/readiness с обязательными negative dependency tests |
| solo | P8.7 | frontier/high | manual | full | recommended: вердикт по 50 метрикам, 66 gate-строкам и overhead — жёсткий гейт перед P3–P7 |
| solo | P8.8 | frontier/high | manual | full | recommended: deferred tracing/метрики после данных P3–P7; mixed scope требует нового atomic design |

---

## 5. Decision Log

Решения находятся в `decisions/`; собрать или проверить их виды: `python3 packages/task/scripts/plan-views.py decisions --plan-dir <plan-dir> [--check]`.
