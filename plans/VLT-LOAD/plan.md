# VLT-LOAD — Программа нагрузочной готовности vaulter

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-LOAD |
| Title | Нагрузочная готовность vaulter: SLO → сценарии → профилирование → память/стриминг → конкуренция → очереди → деградация → наблюдаемость |
| Version | 0.1.0 |
| Status | 🔵 In progress (P0 — проектирование; исполнение фаз — по DoR) |
| Document Type | Executable Master Plan (design-first: до P3 — артефакты и решения, не код) |
| Authoring Model | opus/high (design-профиль task:design; см. §3 Routing — все фазы этого плана роутятся на opus/high) |
| Last Updated | 2026-07-13 |
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
> (провенанс внешних фактов: k6/Postgres/очереди/OTel — 2026, верифицировано через RAG). Факты рекона
> кодовой базы (горячие пути, индексы, память-vs-стриминг, unbounded-scan'ы) вплавлены в §1 и в
> per-phase «Опора в коде» — с точными `file:line`. Никакого чата не требуется: план самодостаточен.

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
  НО **всегда через PHP, без presigned-redirect (D15)** — каждое скачивание занимает PHP-воркер на всю
  длительность (`:26-31`). Один blob на версию, мульти-blob reassembly на download-пути нет (`:90-106`).

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
| P1 (SLO) | opus/high | цифры-обязательства каскадят на все фазы; ошибка тут инвалидирует программу |
| P2 (харнесс), P3 (профилирование БД) | opus/high + `Explore`/`general-purpose` | выбор инструмента, EXPLAIN-методология, индекс-ревизия — решения |
| P4 (память/стриминг) | opus/high | архитектурный риск (RAM `complete()`), может потребовать редизайн сборки |
| P5 (конкуренция), P6 (очереди), P7 (деградация) | opus/high для дизайна сценариев + sonnet/medium для прогонов | дизайн chaos/контеншн-профилей — решение; прогон — механика |
| P8 (наблюдаемость) | opus/high (дизайн метрик-seam) + `vaulter-slice-builder`/`php:laravel-implementer` для кода seam'ов | seam метрик касается публичного контракта пакета |
| Фиксы горячих путей (вне плана) | `/task:dev` + `vaulter-slice-builder` → `vaulter-test-writer` → `vaulter-reviewer` | по D-LOAD-5, отдельными срезами |

---

## 4. Phase Index & Status Board

| Фаза | Название | Гейт | Статус |
|:--|:--|:--|:--|
| P0 | Разведка + RAG-провенанс + этот план | — | 🟢 Done (2026-07-13) |
| P1 | Целевые числа: явные SLO (N tus, ≤5 ГиБ, дерево, p95, RPS) | **гейт P2–P7** | ⚪ Todo |
| P2 | Нагрузочный харнесс: инструмент (k6) + профили трафика | **гейт P3–P7** | ⚪ Todo |
| P3 | Профилирование запросов: EXPLAIN горячих путей, ревизия индексов под объёмом | после P2 **и** P8-минимум | ⚪ Todo |
| P4 | Память и стриминг: бенчмарки ≤5 ГиБ post-фикс L-01 (см. D7/D11 — сам L-01 уже доказан, не гипотеза) | после P2 **и** P8-минимум, после dev-среза L-01 (D11) | ⚪ Todo |
| P5 | Конкуренция в масштабе: сотни контендеров, advisory-lock, пул, sweeper | после P2 **и** P8-минимум | ⚪ Todo |
| P6 | Семантика очередей под завалом: relay/retry/DLQ/идемпотентность | после P2 **и** P8-минимум | ⚪ Todo |
| P7 | Деградация инфраструктуры: S3-отвал, PG-failover, диск-full | после P3–P6 | ⚪ Todo |
| P8 | Наблюдаемость: метрики + трейсы (seam'ы с нуля) | **гейт P3–P7** (минимальный набор seam'ов — до старта замеров) | ⚪ Todo |

Порядок: **P1 → P2 → P8-минимум → {P3, P4, P5, P6 параллельно} → P7**. P8 — не «параллельна и подпирает»,
а гейт: план сам фиксирует (D9), что без метрик P3–P7 не имеют опоры для вердикта («SLO нарушен» нечем
измерить) — значит статус обязан читаться как гейт, а не как фоновая активность. P8 стартует сразу за P2
(её собственная разработка идёт, пока пишутся SLO/харнесс), но P3–P7 не фиксируют результат до готовности
P8-минимума. Поставлена восьмой по нумерации запроса — не по порядку исполнения.

---

## 5. Decision Log

| ID | Решение | Основание |
|:--|:--|:--|
| D1 | **VLT-LOAD — отдельный план, второй в `ACTIVE.md`.** Ось нагрузки ортогональна оси корректности (VLT-MUT); не фаза и не замена. | §1.1 |
| D2 | **Инструмент нагрузки — k6** (дефолт). Locust — резерв для Python/boto3-SigV4-гимнастики. Gatling отклонён: слаб на multi-GB стриминге и resumable-протоколах. | RAG-1 (`findings/P0` — omeronal/ranorex/testguild 2026) |
| D3 | **Стриминг тела в k6 — через `xk6-fasthttp` (кастомный бинарник), НЕ стоковый k6.** Core-k6 грузит файл целиком в RAM (`open()`/`SharedArray`) → OOM генератора на 5 ГиБ × сотни VU. Экспериментальный streams-API — JS-уровень, не HTTP-тело. Для tus допустим и chunk-на-уровне-сценария. | RAG-2 (grafana/k6 #2976/#2974, extension-registry) |
| D4 | **Метрики k6 — `xk6-output-prometheus-remote`**, в тот же кастомный бинарник; распределённо — **k6 Operator** (образ с бинарником в `TestRun.spec.image`). | RAG-3 |
| D5 | **Профилирование БД:** `pg_stat_statements` (reset per run) + `EXPLAIN(ANALYZE,BUFFERS,VERBOSE)` + **HypoPG** для гипотез-индексов; lock-контеншн — `pg_blocking_pids()`-запрос + `log_lock_waits`+`deadlock_timeout=1s`; пул — PgBouncer transaction-mode. | RAG-4 (oneuptime/yonk/monpg/vela 2026) |
| D6 | **Для деревьев проверить порог CTE-vs-ltree.** Recursive-CTE держим (cycle-guard + `withMaxDepth(64)` уже есть), но на глубоких/широких деревьях замерить, где `candidateNodeIds()` (N-CTE per grant) и `MoveNodeAction` (O(subtree) в PHP) ломают SLO; ltree/materialized-path — кандидат-долг, если CTE не тянет. | §1.2 + RAG-4 |
| D7 | **`S3MultipartHandler::complete()` (весь файл в RAM) — это НЕ гипотеза P4, это доказанный Blocker-кандидат уже сейчас (D-LOAD-5a).** Механизм прямой и проверяется арифметикой, не прогоном: `complete()` конкатенирует все парты в одну PHP-строку (`packages/drive/src/Uploads/S3MultipartHandler.php:152-188`), `max_chunk_bytes` (64 MiB) ограничивает ОДИН парт, а не сумму (`readPartWithinLimit()` :230-247) — клиент, объявивший честную 5-ГиБ multipart-загрузку через публичный API, кладёт FPM-воркер по памяти без всякой конкуренции. Это вектор исчерпания памяти, доступный любому пользователю с правом на загрузку, а не только сценарий деградации под нагрузкой. Заведено в §7 как L-01 (Blocker-кандидат, статус «подтверждено», НЕ «подтвердить»). P4 не доказывает эту находку — P4 меряет УЖЕ переписанную стриминговую сборку post-фикс (после того как срез откроется по D11). | §1.2 (S3MultipartHandler.php:152-188), D-LOAD-5a |
| D8 | **Unbounded-scan'ы — первоклассные цели P5/P6.** `SweepUploadsCommand` `->get()`, GC `->cursor()` без batch-cap, `WebhookRetryDispatcher::claimDue()` без limit, `OutboxRelay::subscribedEndpoints` O(endpoints)-в-PHP — измерить деградацию на «грязных» объёмах, завести долг на батчинг/чекпоинты. | §1.2 |
| D9 | **Наблюдаемость строим с нуля (P8), и P8 — ГЕЙТ для P3–P7, не параллельная фаза.** OpenTelemetry PHP SDK → OTLP Collector → Prometheus/Tempo; метрики очереди/GC/sweep/upload/collab и тайминги горячих путей — seam'ы, которых в пакете нет. Без базового набора метрик прогоны P3–P7 не имеют опоры для вердикта («SLO нарушен» нечем измерить) — P8 обязана поставить минимальный набор seam'ов ДО того, как P3–P7 фиксируют результат, а не просто «стартовать сразу за P2» на своих рельсах. | §1.2 + RAG-5 (наблюдаемость=absent) |
| D10 | **Замер — на production-подобии и «грязных» состояниях** (D-LOAD-2/3): тот же Postgres-минор, PgBouncer-режим, реальный S3/MinIO-throttle, предзаполнение (млн сессий/блобов, глубокое дерево). | RAG-4 методология |
| D11 | **Фикс горячего пути (dev-срез по L-01/L-02/L-06 и т.п.) открывается только после того, как VLT-MUT закрыл `vaulter-drive` до Score ≥ 95.** Факт на сегодня (VLT-MUT P0.2 baseline, холодный кэш): `S3MultipartHandler.php` — **38** выживших мутантов, `SweepUploadsCommand.php` — **17**, `MoveNodeAction.php` — **4** (`plans/VLT-MUT/findings/P0.2-drive-escaped.txt`). Рефакторинг под тестами, которые не ловят инверсию условия или пропажу элемента массива, не доказывает, что переписанное поведение корректно — только то, что оно не упало. Порядок: VLT-MUT P2 (`vaulter-drive` → ≥95) закрывается **до** любого dev-среза из §7 по drive-файлам. | D-LOAD-5b, `plans/VLT-MUT/plan.md` P2, `findings/P0.2-baseline-drive.md` |

---

## 6. Phases (детализация)

Каждая фаза: **Цель · Артефакты · DoD · Риск · Опора в коде/RAG · Порядок**. Артефакты складываются в
`plans/VLT-LOAD/` (спеки/отчёты) и в `load/` репо (харнесс/скрипты — тест-инфра, вне пакетного `src/`).

### P1 — Целевые числа: явные SLO 🔴 гейт всего

**Цель.** Превратить «держит нагрузку» в проверяемые обязательства. Без этого остальные фазы меряют шум.

**Артефакты.**
- `plans/VLT-LOAD/slo.md` — таблица SLO по классам операций. Обязательный минимум колонок:
  операция · целевой RPS/конкуренция · размерный профиль · p50/p95/p99 latency-бюджет · error-budget.
- Явно зафиксировать (числа — предложение проектной фазы, утверждает владелец):
  - **Upload (tus):** N параллельных активных сессий (напр. 200/500/1000), размерное распределение
    (p50=5 MiB, p95=500 MiB, max=5 GiB), p95 на инициацию/PATCH/complete.
  - **Download:** конкуррентных стримов (помним: 1 PHP-воркер на весь стрим, D15) → это прямо задаёт
    потолок FPM-воркеров; p95 TTFB и sustained-throughput на файл.
  - **Дерево:** глубина (до 64 — `DEFAULT_MAX_DEPTH`) × ширина (детей на узел), p95 на children/subtree/
    breadcrumb/move; профиль many-grant-актора (число anchor'ов → N-CTE).
  - **Активность/аудит:** write-RPS в `v_activity` (write-hottest), приемлемый лаг фида.
  - **Очередь/webhook:** delivery-RPS, p95 queue-time, устойчивый throughput без роста backlog.
  - **Collab:** одновременных сессий/комнат, comment-broadcast RPS.

**DoD.** `slo.md` заполнен по всем классам; каждый SLO имеет число и источник (бизнес-требование или
явно «инженерная гипотеза, уточнить»); владелец подтвердил порядок величин. Ни одного «TBD» без метки.

**Риск.** SLO из воздуха → оптимизируем не то. Митигация: числа — гипотезы с явной пометкой до
подтверждения владельцем; свип (0.5×…20×) вокруг гипотезы в P2 покажет, где реальный обрыв.

**Опора.** §1.2 (потолок download-воркеров, N-CTE, глубина 64), RAG-5 (форма SLO очереди).

**Порядок.** Первая. Гейт для P2–P7.

---

### P2 — Нагрузочный харнесс: инструмент + профили трафика 🔴 гейт P3–P7

**Цель.** Собрать воспроизводимый генератор нагрузки под tus/S3/tree/queue-сценарии.

**Артефакты.**
- `plans/VLT-LOAD/harness.md` — решение по инструменту (D2: k6) + сборка кастомного бинарника
  (D3: `xk6-fasthttp` + D4: `xk6-output-prometheus-remote`) + как гоняем (локально / k6 Operator k8s).
- `load/` (тест-инфра, вне пакета): k6-скрипты сценариев —
  - `tus-upload.js` — POST create → PATCH offset+chunk (стрим с диска через fasthttp / chunk-на-сценарии)
    → resume-ветка (HEAD offset, докачка) → инъекция обрыва на середине.
  - `s3-multipart.js` — CreateMultipartUpload → UploadPart×N → Complete/Abort (SigV4 в скрипте).
  - `download.js` — конкуррентные стримы (нагрузка на FPM-воркеры).
  - `tree.js` — children/subtree/breadcrumb/move на предзаполненном дереве.
  - `mixed.js` — реалистичный микс + think-time + error-injection.
- Профили трафика: baseline (1×), ramp, spike-бёрст, soak (длинный), stress (до обрыва).
- `load/seed/` — команды предзаполнения БД/стораджа до целевых объёмов (D10).

**DoD.** Кастомный k6-бинарник собран и стримит 5-ГиБ тело **без OOM генератора** (доказать замером
RSS процесса k6); каждый сценарий гоняется и пишет метрики в Prometheus; seed воспроизводит целевой
объём; профили параметризованы (VU/duration/rps из env). Прогон 1× baseline зелёный.

**Риск.** Стоковый k6 → OOM на multi-GB (RAG-2). Митигация: D3 (fasthttp/chunk), проверка RSS в DoD.
Второй риск: SigV4/tus руками → баги харнесса маскируют/выдумывают проблемы. Митигация: контрактный
smoke каждого сценария против реального эндпоинта до нагрузки.

**Опора.** RAG-1/2/3 (`findings/P0`), §1.2 (tus/S3 семантика: offset, part replace, complete).

**Порядок.** Вторая. Гейт для P3–P7.

---

### P3 — Профилирование запросов: EXPLAIN + ревизия индексов под объёмом

**Цель.** Найти полные сканы, N+1 и промахи индексов на горячих путях при **реальных** объёмах.

**Артефакты.**
- `plans/VLT-LOAD/db-profile.md` — по каждому горячему пути: топ из `pg_stat_statements` (total/mean/
  stddev time), `EXPLAIN(ANALYZE,BUFFERS,VERBOSE)`-план, вердикт (index-fast / seq-scan / N-CTE),
  предлагаемый фикс, HypoPG-проверка гипотезы-индекса.
- Ревизия индексов `v_nodes`/`v_blobs`/`v_upload_*`/`v_activity` под объёмом: подтвердить, что тюнинг
  `2026_07_11_000001_optimize_hot_path_indexes.php` держится на млн-строках; найти недостающие.
- Список N+1 (напр. `subscribedEndpoints` в PHP-фильтре — D8) с якорями.

**DoD.** Каждый горячий путь из P1 имеет EXPLAIN-план на предзаполненной БД; каждый seq-scan/N-CTE/N+1
либо оправдан, либо заведён долгом (§6-долг) с HypoPG-подтверждённым индексом-кандидатом;
`pg_stat_statements` top-20 разобран.

**Риск.** EXPLAIN на пустой БД врёт (планировщик выбирает seq-scan на малых таблицах). Митигация: D10
(предзаполнение), `ANALYZE` перед замером, реалистичная статистика.

**Опора.** §1.2 (candidateNodeIds N-CTE, MoveNodeAction O(subtree)), §1.3 (индексы), RAG-4 (методология,
HypoPG, CTE-vs-ltree).

**Порядок.** После P2. Параллельна P4/P5/P6.

---

### P4 — Память и стриминг: бенчмарки ≤5 ГиБ

**Цель.** Доказать, где линейное становится квадратичным / где тянем файл в RAM. **L-01
(`S3MultipartHandler::complete()` — весь файл в RAM) в эту цель НЕ входит: он уже доказан арифметикой
(D7/D-LOAD-5a), не прогоном, и уже Blocker-кандидат в §7 со статусом «подтверждено».** P4 не открывает
эту находку заново — P4 меряет РЕЗУЛЬТАТ фикса (после того как dev-срез по L-01 закрыт под D11), и ищет
остальные пути линейное→квадратичное (tus-PATCH, S3-putPart, CAS-stage, download).

**Артефакты.**
- `plans/VLT-LOAD/memory-profile.md` — RSS/peak-memory FPM-воркера по путям: tus-PATCH, S3-putPart,
  **S3-complete — пост-фикс, стриминговая сборка**, CAS-stage, download — на 5 MiB / 500 MiB / 5 GiB.
- Регрессионное подтверждение: пост-фикс `complete()` НЕ показывает линейный рост RSS с размером файла
  (иначе фикс не закрыл L-01 — возврат в §7).

**DoD.** Есть измеренная кривая память-vs-размер по каждому пути; пост-фикс `complete()` держит
константный (или логарифмический от `max_chunk_bytes`) потолок RSS независимо от размера файла;
`max_chunk_bytes`-защита проверена системно (по сумме, не только по одному парту) — если пост-фикс
всё ещё копит сумму без потолка, это новая находка §7, не закрытие L-01.

**Риск.** Замер памяти PHP-FPM нетривиален (пулы, opcache). Митигация: `memory_get_peak_usage(true)` +
внешний RSS процесса воркера; изолированный воркер на прогон.

**Опора.** §1.2 (S3MultipartHandler.php:152-188, readPart, streamFor php://temp; download 256 KiB),
D7, D11, D-LOAD-5a.

**Порядок.** После P2, P8-минимума, **и после закрытия dev-среза L-01** (который сам ждёт VLT-MUT
`vaulter-drive` ≥95 по D11). Параллельна P3/P5/P6.

---

### P5 — Конкуренция в масштабе: сотни контендеров

**Цель.** Не 2 процесса как в `tests/Concurrency`, а сотни: контеншн advisory-lock, дедлоки, исчерпание
пула, sweeper на миллионе сессий.

**Артефакты.**
- `plans/VLT-LOAD/concurrency-report.md`:
  - Advisory-lock под нагрузкой: доля 409 (NOWAIT false) на per-session lock при N контендерах на одну
    сессию и на разные; коллизии int4-ключа CAS-лока (`BlobLock` hashtext) при storm дедупа.
  - Пул соединений: поведение при исчерпании (PgBouncer transaction-mode), очередь ожидания, «too many
    clients», таймауты `pg_advisory_xact_lock`.
  - Дедлоки: `log_lock_waits` + `pg_stat_database.deadlocks` под reparent/dedup-storm.
  - **Sweeper на грязной БД:** поведение `SweepUploadsCommand ->get()` на 1 млн просроченных сессий —
    время, память, блокировка live-PATCH (D8).
  - Дерево-конкуренция: параллельные move/reparent (сейчас 0 покрытия).

**DoD.** Каждый лок-путь имеет замер контеншена при целевом N; sweeper прогнан на 1 млн сессий с
зафиксированным временем/RSS и вердиктом (нужен ли батчинг/chunk — долг); исчерпание пула
воспроизведено и описано (graceful queue vs обрыв); дедлок-счётчик под storm снят.

**Риск.** Воспроизвести N>сотни контендеров на одну сессию сложно и может быть нереалистично.
Митигация: два профиля — «много на одну» (worst-case лока) и «много на разные» (реалистичный throughput).

**Опора.** §1.2 (UploadSessionStoreRepository:80-114, BlobLock:35-49, SweepUploadsCommand:37,
tests/Concurrency = 2 процесса), RAG-4 (pg_blocking_pids, PgBouncer, deadlock monitoring).

**Порядок.** После P2. Параллельна P3/P4/P6.

---

### P6 — Семантика очередей под завалом

**Цель.** Ретраи, backoff, DLQ, идемпотентность, единственный владелец ретрая — под 5–10× пик.

**Артефакты.**
- `plans/VLT-LOAD/queue-report.md`:
  - Relay под завалом: `OutboxRelay::process(limit=100)` throughput; **`WebhookRetryDispatcher::claimDue()`
    без limit** — размер lock-set и время транзакции на большом backlog (D8).
  - `OutboxRelay::subscribedEndpoints()` O(endpoints)-в-PHP — деградация с ростом числа endpoint'ов (D8).
  - Идемпотентность: доказать, что повторный `DeliverWebhook`/relay-tick не дублирует доставку
    (event_id UNIQUE, terminal no-op); поведение при overlapping-тиках (visibility-timeout re-arm).
  - Backoff/DLQ: кривая `Config::webhookBackoff(attempt)`, паркинг в `dead`, auto-disable endpoint'а.
  - Единственный владелец ретрая: подтвердить, что при параллельных `relay-outbox`+`retry-webhooks` нет
    двойного fire (claim-window re-arm).

**DoD.** Throughput relay при 5–10× измерен; `claimDue` без limit доказан как риск на backlog (долг на
limit/chunk); идемпотентность доказана прогоном с намеренными дублями; backoff/DLQ-переходы наблюдаемы;
overlapping-тики не двоят доставку.

**Риск.** Нет `$timeout` у `DeliverWebhook` → зависший HTTP держит воркер (bounded только
`Config::webhookTimeout`). Митигация: включить в замер worker-occupancy при медленном/зависшем endpoint'е.

**Опора.** §1.2 (DeliverWebhook:45, OutboxRelay:57-79/100-110, WebhookRetryDispatcher:59-73), RAG-5
(DLQ/backoff/idempotency, per-queue SLO).

**Порядок.** После P2. Параллельна P3/P4/P5.

---

### P7 — Деградация инфраструктуры (chaos)

**Цель.** S3 отвалился на середине multipart; Postgres ушёл в failover; диск кончился.

**Артефакты.**
- `plans/VLT-LOAD/chaos-report.md` — по сценарию: инъекция · ожидаемое поведение · факт · вердикт:
  - **S3 5xx/latency/недоступность** на середине multipart-upload и на CAS-promote: ретраи, чистка
    staged-temp, попадание в терминал, живёт ли resume; поведение sweeper при недоступном S3 (Abort).
  - **PG failover:** оборванные транзакции с advisory-lock (лок снимается на разрыве сессии —
    проверить, что не остаётся «повисших» сессий); переигрывание in-flight commit; PgBouncer
    reconnection.
  - **Диск-full** на staged-temp (`storage/app/tmp`): классификация ошибки (disk vs business),
    восстановление после освобождения, отсутствие corrupted partial-state.
  - Восстановление: после снятия сбоя — корректны ли повторы, идемпотентность, объём DLQ.

**DoD.** Каждый сценарий прогнан с инъекцией и откатом; для каждого зафиксировано: держит ли
idempotency, куда падает (DLQ/terminal/resume), даёт ли observability root-cause; повисшие ресурсы
(сессии/локи/staged-temp) после сбоя — либо нет, либо заведён долг.

**Риск.** Chaos на реальном S3/PG рискован; нужна изолированная среда. Митигация: MinIO с fault-injection
+ toxiproxy для latency/partition; PG failover на локальном кластере, не на shared.

**Опора.** §1.2 (reclaim/abort пути, staged discard, GC serializable-retry), RAG-5 (chaos-методология:
S3/disk/network).

**Порядок.** После P3–P6 (нужны их боттлнеки, чтобы знать, что деградирует первым).

---

### P8 — Наблюдаемость: метрики + трейсы (seam'ы с нуля)

**Цель.** Узнавать о нагрузке из метрик, а не от пользователей. Сегодня наблюдаемости в пакете нет.

**Артефакты.**
- `plans/VLT-LOAD/observability-spec.md` — что инструментируем и какими seam'ами:
  - Метрики (Prometheus через OTel Collector): `upload_bytes_total`, `upload_session_active`(gauge),
    `patch_conflict_total`(409), `blob_dedup_hit_total`, `gc_swept_total`/`gc_duration_seconds`,
    `sweep_expired_total`/`sweep_skipped_total`, `outbox_backlog`(gauge), `webhook_delivery_seconds`
    (hist), `webhook_dlq_total`, `tree_query_seconds`(hist), `collab_session_active`(gauge).
  - Трейсы (OTel PHP SDK → OTLP Collector → Tempo/Jaeger): HTTP→Controller→Action→repo; спаны на
    PATCH/putPart/complete/commit/GC/sweep/relay; атрибуты upload_id/node_id/tenant/attempt.
  - Health: `/up` liveness + readiness (БД/S3/очередь) — spatie/laravel-health кандидат
    (skill `laravel-testing:health-checks`).
- Спека seam'ов в пакетном `src/` (это **единственный код-выход VLT-LOAD**, отдельными dev-срезами):
  где вешаются таймеры/счётчики без хард-зависимости на конкретный backend (контракт-шов, как AzGuard).

**DoD (двухступенчатый — гейт срабатывает на P8-минимуме, не на полном закрытии фазы).**
- **P8-минимум (гейт P3–P7):** таймеры/счётчики латентности на горячих путях каждого класса SLO из
  P1 (upload/download/tree/activity/queue/collab) + `queue_backlog_size`/`gc_duration_seconds`/
  `sweep_*_total` seam'ы на unbounded-scan'ы (§7) — этого достаточно, чтобы P3–P7 могли зафиксировать
  вердикт «SLO нарушен/держится» по цифрам, а не на глаз. Health-`/up` — часть минимума (иначе харнесс
  P2 не отличит «упало» от «недоступно»).
  Без этого набора P3–P7 не стартуют замер (см. §4 статус-борд — P8 гейт, не «параллельна»).
- **Полное закрытие фазы:** остаток спеки (трейсы, редкие метрики) — после того как P3–P7 показали
  приоритет; дополняется, не блокирует уже идущие фазы.

**Риск.** OTel PHP + FPM overhead под нагрузкой сам искажает замер. Митигация: sampling, замер
overhead'а инструментации отдельным прогоном (with/without).

**Опора.** §1.2 (наблюдаемость=absent, unbounded-scan'ы), RAG-5 (OTel→Collector→Prometheus/Tempo, метрики
очереди), skill `architect:observability-design`, `laravel-testing:health-checks`, D9.

**Порядок.** Стартует сразу после P2. **P8-минимум обязан быть готов до старта P3–P7** (гейт — D9,
§4). Полное закрытие фазы — последним, после того как P3–P7 показали приоритеты инструментации.

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
