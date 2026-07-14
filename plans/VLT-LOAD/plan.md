# VLT-LOAD — Программа нагрузочной готовности vaulter

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-LOAD |
| Title | Нагрузочная готовность vaulter: SLO → сценарии → профилирование → память/стриминг → конкуренция → очереди → деградация → наблюдаемость |
| Version | 0.2.0 |
| Status | 🟡 In progress (P0 🟢; P1 🟢 Done — 5/5, sign-off получен; следующая — P2, скелет) |
| Document Type | Executable Master Plan (design-first: до P3 — артефакты и решения, не код) |
| Authoring Model | opus/high (design-профиль task:design; см. §3 Routing — все фазы этого плана роутятся на opus/high) |
| Last Updated | 2026-07-14 |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-drive, vaulter-documents, vaulter-media, vaulter-filament |
| Стек | PHP 8.4, Laravel 12/13-contracts, Postgres 16, Flysystem+S3, tus/S3-multipart, staudenmeir HasRecursiveRelationships, Laravel queues, Reverb/Hocuspocus |
| Execution Mode | phase-first: P1 (SLO) — гейт для всего; P2 (харнесс) — гейт для P3–P7; P8 (наблюдаемость) — параллельна и подпирает все замеры |
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

| Items | Model/effort · Tool Capability | Why |
|:--|:--|:--|
| P1.1, P1.3 (тиры, SLO-числа) | opus/high | цифры-обязательства каскадят на все фазы; ошибка тут инвалидирует программу |
| P1.2 (каталог операций) | sonnet/medium + `Explore` | рекогносцировка маршрутов/команд с якорями — механика, не решение; критерий полноты машинный (обход `Route::` + `Console/`) |
| P1.4 (гейт-политика) | opus/high + tool-capability `context7`/`perplexity-web` (RAG) | синтаксис порогов k6 и политика baseline — внешние факты; «по памяти» = дефект (см. `findings/P1-rag-slo-ci-gate.md`) |
| P1.5 (sign-off) | человек (владелец) + sonnet/low на механическую правку статусов | решение не делегируется модели; исполнитель лишь готовит decision-sheet и переносит ответ в артефакты |
| P2 (харнесс), P3 (профилирование БД) | opus/high + `Explore`/`general-purpose` | выбор инструмента, EXPLAIN-методология, индекс-ревизия — решения |
| P4 (память/стриминг) | opus/high | архитектурный риск (RAM `complete()`), может потребовать редизайн сборки |
| P5 (конкуренция), P6 (очереди), P7 (деградация) | opus/high для дизайна сценариев + sonnet/medium для прогонов | дизайн chaos/контеншн-профилей — решение; прогон — механика |
| P8 (наблюдаемость) | opus/high (дизайн метрик-seam) + `vaulter-slice-builder`/`php:laravel-implementer` для кода seam'ов | seam метрик касается публичного контракта пакета |
| Фиксы горячих путей (вне плана) | `/task:dev` + `vaulter-slice-builder` → `vaulter-test-writer` → `vaulter-reviewer` | по D-LOAD-5, отдельными срезами |

---

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Разведка + RAG-провенанс + этот план | 1/1 | 🟢 Done |
| P1 | Целевые числа: явные SLO (N tus, ≤5 ГиБ, дерево, p95, RPS) · **гейт P2–P8** | 4/5 | 🟢 Done |
| P2 | Нагрузочный харнесс: инструмент (k6) + профили трафика · **гейт P3–P7** · после P1 | 0/1 | ⬜ Not started |
| P3 | Профилирование запросов: EXPLAIN горячих путей, ревизия индексов под объёмом · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P4 | Память и стриминг: бенчмарки ≤5 ГиБ post-фикс L-01 (D7/D11 — сам L-01 уже доказан, не гипотеза) · после P2, P8-минимума и dev-среза L-01 | 0/1 | ⬜ Not started |
| P5 | Конкуренция в масштабе: сотни контендеров, advisory-lock, пул, sweeper · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P6 | Семантика очередей под завалом: relay/retry/DLQ/идемпотентность · после P2 **и** P8-минимума | 0/1 | ⬜ Not started |
| P7 | Деградация инфраструктуры: S3-отвал, PG-failover, диск-full · после P3–P6 | 0/1 | ⬜ Not started |
| P8 | Наблюдаемость: метрики + трейсы (seam'ы с нуля) · **гейт P3–P7** по минимальному набору seam'ов | 0/1 | ⬜ Not started |

Детализация фаз — в `phases/Pn.md` (P1 развёрнута на 5 items; P2–P8 — скелеты с блупринтами P0,
разворачиваются `/task:plan-design VLT-LOAD Pn`).

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

---

## 7. Findings-долг (заполняется по ходу)

Боттлнеки, найденные фазами, но НЕ чинимые внутри них (D-LOAD-5). Каждый → отдельный dev-срез.
Колонка «VLT-MUT-долг» — выжившие мутанты в этом файле (`P0.2-drive-escaped.txt`, холодный кэш,
2026-07-13) — держит D11: dev-срез не открывается, пока drive не закрыт до ≥95.

| ID | Severity | Находка | Якорь | VLT-MUT-долг | Фаза-источник | Статус |
|:--|:--|:--|:--|--:|:--|:--|
| L-01 | **Blocker-кандидат** (подтверждено арифметикой, D-LOAD-5a — НЕ гипотеза) | `S3MultipartHandler::complete()` собирает весь файл в RAM; `max_chunk_bytes` ограничивает один парт, не сумму — любой пользователь с правом на загрузку объявляет честную 5-ГиБ multipart-загрузку и кладёт FPM-воркер по памяти без конкуренции | `packages/drive/src/Uploads/S3MultipartHandler.php:152-188` | 38 | доказано в §1.2/D7 при проектировании; P4 меряет пост-фикс | 🟡 **готово к dev-срезу — ждёт только D11 (VLT-MUT drive ≥95)** |
| L-02 | (гипотеза) Med | `SweepUploadsCommand ->get()` без limit/chunk на млн сессий | `packages/drive/src/Console/SweepUploadsCommand.php:37` | 17 | P5 | ⚪ подтвердить в P5; фикс — после D11 |
| L-03 | (гипотеза) Med | GC `->cursor()` без batch-cap/чекпоинта на млн orphan-блобов | `packages/media/src/Cas/BlobGarbageCollector.php:293-298` | — (пакет media, не drive; VLT-MUT баланс media вне текущего P0.2) | P6/P5 | ⚪ подтвердить |
| L-04 | (гипотеза) Med | `WebhookRetryDispatcher::claimDue()` без limit — unbounded lock-set | `packages/core/src/Webhooks/WebhookRetryDispatcher.php:59-73` | — (пакет core) | P6 | ⚪ подтвердить |
| L-05 | (гипотеза) Low-Med | `OutboxRelay::subscribedEndpoints()` O(endpoints)-в-PHP на outbox-строку | `packages/core/src/Webhooks/OutboxRelay.php:100-110` | — (пакет core) | P3/P6 | ⚪ подтвердить |
| L-06 | (гипотеза) Med | `candidateNodeIds()` — N-CTE per grant; `MoveNodeAction` O(subtree) в PHP | `NodeReadRepository.php:454-529` (core), `MoveNodeAction.php:85-100` (drive) | MoveNodeAction: 4 | P3/P5 | ⚪ подтвердить в P3/P5; фикс `MoveNodeAction` — после D11 |
| L-07 | (гипотеза) Low | CAS-лок int4-ключ (`hashtext`) — коллизии при storm дедупа | `packages/core/src/Cas/BlobLock.php:35-49` | — (пакет core) | P5 | ⚪ подтвердить |
| L-08 | (факт) Low | Sweeper/relay/GC **не зарегистрированы в расписании** пакетом — хост-долг | `SweepUploadsCommand`, `RelayOutboxCommand` docblocks | — | P8/RUNBOOK | ⚪ задокументировать |

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
**Рекомендация:** k6 + xk6-fasthttp как основной харнесс; Locust держать в резерве, если S3-SigV4-скриптинг
в k6 окажется болезненным (RAG-1/2 в `findings/P0`).

### 8.2 Стриминг в k6: кастомный бинарник vs chunk-на-сценарии

Два пути обойти RAM-загрузку тела. **Кастомный бинарник (xk6-fasthttp)** — истинный стрим тела с диска,
ближе к реальному tus-клиенту, но сборка/поддержка образа. **Chunk-на-сценарии** — слать PATCH-ями по
`chunk_size`, не держа файл целиком; для tus это натуральный профиль (offset-приращения), нулевой
сборочный оверхед, но для S3-part'ов крупнее — часть в память всё равно попадёт.
**Рекомендация:** для tus-сценария — chunk-на-сценарии (проще, натурально); для S3-multipart и «честного»
5-ГиБ single-stream — кастомный бинарник. Оба варианта в P2.

### 8.3 Статус P8 — «параллельна» vs «гейт» (пересмотрено)

Первая редакция плана статусила P8 как «параллельна, подпирает P3–P7». Ревью верно указало
противоречие: тот же абзац утверждал «без метрик прогоны P3–P7 слепые» — а слепой прогон не
может быть гейтом ЗА собой, значит фаза, которая делает его не-слепым, обязана быть гейтом ПЕРЕД
ним. **Решение (D9, зафиксировано):** P8 — гейт P3–P7 по минимальному набору seam'ов (латентность
горячих путей каждого SLO-класса из P1 + `/up`), не по полному закрытию фазы. Двухступенчатый DoD
(§ фаза P8) разрешает противоречие: P8-минимум блокирует старт P3–P7, полное закрытие спеки —
нет, оно донабирается по итогам P3–P7. «Параллельна» осталась неверной формулировкой первой
редакции — её замена на «гейт P3–P7» в §4/D9 это и есть исправление, а не альтернатива для выбора.

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

---

## 9. Провенанс

| Источник | Где | Что дал |
|:--|:--|:--|
| RAG (perplexity-web) | `findings/P0-rag-load-tooling.md` | k6/Gatling/Locust 2026, k6-стриминг-ловушка, xk6, Postgres-профилирование, очереди/OTel/chaos |
| Рекон кодовой базы | §1.2/§1.3 плана (якоря file:line) | горячие пути, индексы, память-vs-стриминг, unbounded-scan'ы, наблюдаемость=absent |
| mAInd память | scope `project:vaulter` | M0–M4b контекст (CAS, uploads, tree, collab, outbox) |

Внешние ссылки первоисточников перечислены в `findings/P0-rag-load-tooling.md` (omeronal, ranorex,
testguild, grafana/k6 issues, grafana docs, oneuptime, yonk, monpg, vela, itmarkerz, opentelemetry.io).
