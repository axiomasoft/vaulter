# Инженерные стандарты, матрица стека, лицензии, SLO и стоп-краны CI экосистемы Vaulter

Документ является полностью самодостаточным нормативным источником инженерных ограничений, проверенных версий, лицензионных границ, SLO-бюджетов и CI-гейтов для всех **5 репозиториев** экосистемы (`vaulter`, `vaulter-collaboration`, `vaulter-sheets`, `vaulter-ai`, `vaulter-editor-pro`). Актуализирован по состоянию экосистемы на **ноябрь 2026 года**.

---

## 1. Матрица рантаймов, библиотек и CI-инструментов (ноябрь 2026)

| Слой | Базовый минимум (CI и сборка) | Расширенная матрица / целевой ориентир | Обязательные технические ограничения и закрытые CVE |
| --- | --- | --- | --- |
| **PHP** | `>=8.4 <8.6` (`^8.4 \|\| ^8.5`) | Ночной CI на PHP 8.6.0 GA | Совместимость с PHP 8.4 для хостов FlexCms/FlexCrm. Не переопределять `array_first()`/`array_last()` (из `symfony/polyfill-php85`, использовать `Arr::first()`). На 8.5+ применять `#[\NoDiscard]` (квитанции/тикеты/`with*`), `clone($this, [...])`, `Uri\WhatWg\Url` (не `Uri\Rfc3986\Uri` для SSRF!). На 8.4+ — `public private(set)` и `ReflectionClass::newLazyGhost()` |
| **Laravel** | `illuminate/*: ^13.0` (`^13.38.0`) | `orchestra/testbench: ^11.3`, `laravel/octane: ^2.12.1` | Учитывать breaking changes Laravel 13: `VerifyCsrfToken` → `PreventRequestForgery::class` (покрывает и `Route::query()`), allowlist десериализации кеша, JSON-сериализация сессий, смена дефолтных префиксов кеша/сессий с `_` на `-` (фиксировать явно в конфиге), баг `DatabaseTransactionsManager #61964` при ошибке на `COMMIT` (обязателен `SET CONSTRAINTS ALL IMMEDIATE` и изоляция `afterCommit` в `VaulterDatabase`) |
| **PostgreSQL** | **PostgreSQL 18** (`18.7+`) | Обязательная CI-матрица: **PG 18.7 + PG 19.0 GA** | Базовый `vaulter` сохраняет кросс-драйверную проверку (PG/MySQL/SQLite) и `IdStrategyResolver`. Закрытые пакеты ориентированы на PG 18/19 (`DEFAULT uuidv7()`, `timestamptz`). Учитывать: `SQLSTATE 23001` наряду с `23503`, `STORED` для индексируемых generated columns, **запрет `REPACK (CONCURRENTLY)` на горячих таблицах** (не MVCC-безопасен в PG 19!), замену `io_workers` на 4 параметра и удвоение `max_locks_per_transaction` при переходе на PG 19 |
| **Node.js (Hub, Runtime, Build)** | **Node.js `>=26.13.1` LTS** ('Lithium') | Единый runtime для `@vaulter/*`, Hub, `sheets-runtime`, `sheets-realtime` | `26.13.1` закрывает High-CVE в HTTP/2 (**CVE-2026-12386**), `llhttp`, `undici`. В Docker-образах: Debian/Ubuntu (`bookworm`/`trixie`, GCC >= 13.2) → `libatomic1`; RHEL/AL2023 → `libatomic`; **Alpine → `apk add --no-cache libatomic libstdc++`**; обязательный smoke `RUN node -e "process.exit(0)"` |
| **Vue.js** | **`vue: >=3.5.42`** (целевой `3.5.46`, MIT) | `3.5.x` stable | Версии `<= 3.5.41` уязвимы к SSR XSS (**GHSA-g2v6-rqmx-r4w6**). Запрещён экспериментальный Vapor Mode 3.6 RC |
| **Filament & Livewire** | `filament/filament: ^5.11.4` / `livewire/livewire: ^4.4.0` (MIT) | Laravel 13 | В Livewire v4 эндпоинт сменился на хешированный **`/livewire-{hash}/update`** (учитывать в WAF/Nginx/RateLimiter). Платный Filament Blueprint не используется |
| **Tiptap & ProseMirror** | **`@tiptap/core: >=3.34.0`**, `@tiptap/y-tiptap: ^3.0.10`, `y-prosemirror: ^1.3.7`, `prosemirror-changeset: ^2.4.4` (MIT) | Tiptap 3 OSS | `>=3.34.0` закрывает **CVE-2026-104847** (CVSS 8.5 ReDoS) и prototype pollution `mergeAttributes`. Хук `dispatchTransaction` для Tracked Changes; защитная обёртка от бага `@tiptap/y-tiptap #8614` (`pos === 1`). Запрещён нестабильный `@y/prosemirror` |
| **Collaboration Hub & Yjs** | **`@hocuspocus/server: >=4.9.0`**, **`yjs: >=13.6.33`**, **`lib0: >=0.2.119`**, `y-protocols: ^1.0.7` (MIT) | Единый lockfile + дедупликация `yjs` | `4.9.0` закрывает `#1123` (зависание очереди), `#1155/#1157/#1163/#1174` и `#1206` (рассылка до загрузки); `yjs >= 13.6.33` закрывает расхождение `#823`. **Запрещён `Y.UndoManager` на серверном `Y.Doc`** (баг `#833`). В строгом профиле `3.5` запрещены `@hocuspocus/extension-redis` (для данных) и `DirectConnection.transact` |
| **Univer OSS & XLSX/DOCX** | **`@univerjs/*: 1.5.2`** (Apache-2.0, строгий единый пин), **`ironcalc: 0.9.1`** (MIT/Apache-2.0), **`fflate: 0.8.3`**, **`saxes: 6.0.0`**, **`fast-xml-parser: >=5.3.5`**, **`yauzl: ^3.4.0`**, **`docx: 9.16.0`** | Plugin Mode в Node (`preset-sheets-node-core@1.0.4` заброшен) | `fast-xml-parser >= 5.3.5` с `processEntities: false` закрывает **GHSA-m7jm-9gc2-mpf2**. Трёхстрочный `pnpm patch` на `@univerjs/engine-formula` поднимает AST LRU-кеш `#7936` с `5000` до `32768` |
| **AI & SSE транспорт** | **`laravel/ai: ^1.3.0`** (MIT), опц. `neuron-core/neuron-ai: ^2.8.11` (MIT), **`centrifugo: >=6.9.0`** (Apache-2.0) | Octane 25s Bounded-SSE или Centrifugo | В `laravel/ai v1.3.0` есть баг обхода согласования через субагента (`#1206`) → `office.commit` обязан проверять P/Q в БД. `Centrifugo >= 6.9.0` закрывает **CVE-2026-71485** (CVSS 9.1) и **CVE-2026-32301** (CVSS 9.3) |
| **Antivirus & Storage** | **`ClamAV (clamd): >=1.5.5`** (внешний процесс по сокету), `league/flysystem: ^3.29.0` + `flysystem-aws-s3-v3`, `maennchen/zipstream-php` | Внешний S3 / SeaweedFS (Apache-2.0) | `ClamAV >= 1.5.5` закрывает критический format-string **CVE-2026-76422** (+ 76411/76421/76430 в `1.5.0–1.5.4`). Запрещена линковка `libclamav` (GPL-2.0) |
| **OpenTelemetry** | **`ext-opentelemetry: >=1.4.2` (`1.5.0`)** + **`ext-protobuf`** (через **PIE**, не PECL), `open-telemetry/sdk: ^1.16.1`, OTel JS `^3.0.0` | Async OTLP HTTP/protobuf | `>=1.4.2` закрывает баг памяти PHP JIT `#330`. Атрибуты строго в пространстве `vaulter.*` (запрет `app.*`). В OTel Collector использовать встроенный батчинг экспортера (отдельный `batch` processor устарел) |
| **CI / Статанализ / Supply Chain** | `phpstan/phpstan: ^2.3.6`, `larastan/larastan: ^3.15.0`, `spaze/phpstan-disallowed-calls: ^4.15.1`, `pestphp/pest: ^4.7.2` / `^5.3.1`, `roave/backward-compatibility-check: ^8.23.0`, `@microsoft/api-extractor: ^7.60.1`, `dedoc/scramble: ^0.13.55`, `deptrac/deptrac`, **Sigstore `cosign: >=3.0.2`** | `splitsh-lite` (`fetch-depth: 0`) + Changesets + OIDC | `dedoc/scramble >= 0.13.50` чинит Octane `#1307` и PHP 8.5 `#1305`. PHPStan не должен сканировать `vendor/` целиком (`#15706`). В Pest 5 учитывать конфликт `--parallel` (`#1958`) и `--shard` + `--coverage` (`#1432`). Docker Notary v1 отключается 08.12.2026 → подпись образов строго по `@sha256` через `cosign >= 3.0.2`. Для OIDC Trusted Publishing: защищённый GitHub Environment + `git merge-base --is-ancestor "$GITHUB_SHA" origin/main` |

---

## 2. Постоянные архитектурные запреты («Стоп-краны» CI)

Действуют во всех **5 репозиториях** и проверяются механически до ручного ревью:

1. **Запрет утечки Eloquent наружу:** `Illuminate\Database\Eloquent\Model` запрещён в параметрах, возвращаемых типах и полях публичных `#[Api]` контрактов, событий и DTO.
2. **Строгое владение таблицами и изоляция `Internal` (ADR-0030):** запрещено импортировать чужие `#[Internal]`/`*\Internal\*` классы и обращаться прямым SQL к таблицам чужого пакета/репозитория.
3. **Запрет 32-битного `hashtext()` и сессионного `pg_advisory_lock()`:** на PostgreSQL разрешён **только** транзакционный 64-битный `pg_advisory_xact_lock(hashtextextended(?, 0))` / `pg_advisory_xact_lock_shared(hashtextextended(?, 0))` / `pg_try_advisory_xact_lock(hashtextextended(?, 0))`.
4. **Запрет сессионного контекста тенанта и сырого `DB::transaction()`:** запрещены `SET app.tenant_id` / `set_config(..., false)` и вызовы `DB::transaction()` в обход `VaulterDatabase::transaction()`. Разрешён только транзакционный `SELECT set_config('vaulter.tenant_id', ?, true)` внутри `VaulterDatabase::transaction()`.
5. **Безопасность Octane / FrankenPHP / Swoole и воркеров:** запрещено хранить состояние запроса, тенанта, субъекта или открытого диска в свойствах `singleton()`-сервисов и статических полях; контекст в очереди передаётся через `Illuminate\Support\Facades\Context` и `RestoreTenantContext`, все ключи кеша имеют префикс тенанта.
6. **Запрет dispatch до коммита БД и защита от бага Laravel `#61964`:** публикация событий и постановка заданий — строго через транзакционный `v_outbox` либо строго идемпотентные `afterCommit`-колбэки с локальной защитой `VaulterDatabase` от проваленного `COMMIT`.
7. **Жёсткий SSRF-барьер вебхуков:** запрещены `parse_url()` (и `Uri\Rfc3986\Uri` для SSRF) и `CURLOPT_RESOLVE`. Обязательны `Uri\WhatWg\Url` (PHP 8.5) / `idn_to_ascii` (PHP 8.4), запрет `userinfo`, запрет trailing-dot (`example.com.`), проверка всех A/AAAA (включая `::ffff:0:0/96`), запрет 3xx редиректов, пиннинг через `CURLOPT_CONNECT_TO` и **`CURLOPT_FORBID_REUSE => true`**.
8. **Безопасность ненадёжных архивов (ZIP / XLSX / DOCX):** запрещён `ZipArchive::extractTo()`. До чтения обязателен `ZipCentralDirectoryGuard` (`yauzl` с проверкой непересечения байтовых диапазонов в Node, `fflate` в браузере, `ZipArchive::RDONLY` + `getExternalAttributesIndex` в PHP): запрет `..`, `\`, `\0`, ведущих `/`, дисков Windows, дубликатов имён без учёта регистра, UNIX-симлинков `S_IFLNK` (`0120000`) и спецфайлов, квоты (<= 2 000 entries, <= 50 MiB/файл, <= 150 MiB всего, ratio <= 100×), запрет `<!DOCTYPE`/`<!ENTITY`/`XInclude`, `processEntities: false` (`fast-xml-parser >= 5.3.5`) и белый список `TargetMode="External"` **исключительно для `relationships/hyperlink`** (`https:`, `http:`, `mailto:`).
9. **Ноль неиндексированных FK, запрет `REPACK` на горячих таблицах и безопасные миграции:** все FK покрыты валидными нечастичными B-tree индексами (`scripts/ci/sql/unindexed-fks.sql` в `schema.sh`); новые отложенные FK объявляются как **`DEFERRABLE INITIALLY IMMEDIATE`** (с `SET CONSTRAINTS ALL IMMEDIATE` до `commit()`); продакшен-миграции задают `SET LOCAL lock_timeout = '2s'`, `SET LOCAL statement_timeout = '15s'`, Expand-Contract (`NOT VALID` + `VALIDATE CONSTRAINT`); запрещён `REPACK (CONCURRENTLY)` на горячих таблицах; запрещено выдавать `uuidv7()` как секретные токены доступа.
10. **Чистота кодовой базы и запрет обхода проверок:** `spaze/phpstan-disallowed-calls` банит `DB::transaction()`, `set_config(..., false)`, `parse_url()`, `ZipArchive::extractTo()`, `VerifyCsrfToken::class`, `hashtext(`, `pg_advisory_lock(`, `env()` вне `config/*.php`; запрещены папки `Helpers/`, `Utils/`, `Common/`, `Misc/`, `Traits/` и несанкционированные `@phpstan-ignore` / `@psalm-suppress` / `->skip()`.
11. **Лицензионная чистота (Zero-Royalty Commercial Guard):** в production-зависимостях всех 5 репозиториев разрешены **только MIT, Apache-2.0, BSD, ISC**. В CI (сканер лицензий на каждом PR) жёстко запрещены:
    - коммерческие/облачные пакеты: `@univerjs-pro/*`, `registry.tiptap.dev` / `@tiptap-pro/*`, `handsontable`, Filament Blueprint;
    - копилефт-пакеты: `hyperformula` (GPLv3), `dunglas/mercure` (AGPL-3.0), встроенный `minio/minio` (AGPL-3.0 — использовать внешнее S3-облако или `SeaweedFS` Apache-2.0), линковка `libclamav` (GPL-2.0 — только внешний процесс `clamd >= 1.5.5`), любые GPL/AGPL/SSPL runtime-зависимости;
    - пакеты без лицензии / уязвимые устаревшие пакеты: `@univer-cli/*` (`#7747`), `xlsx@0.18.5` (SheetJS), `exceljs` / `unzipper` (`buffers@0.1.1`), `mammoth` (`CVE-2025-11849`), `Centrifugo < 6.9.0`, `ClamAV < 1.5.5`.
12. **Универсальность движка коллаборации и хранения (Google Workspace Canon):** базовое хранение (`v_documents`, `v_document_payloads` + CAS-вынос > 512 КБ, `v_document_sync_states`, ревизии) и единый PHP-журнал совместных комнат (`vaulter-collaboration`: `v_collaboration_heads.protocol`, `v_collaboration_updates`, `v_collaboration_receipts`, `owner_fence`, Room JWT, `4409`/`4412`) обязаны оставаться протокольно- и движково-нейтральными для подключения `yjs/2`, `univer-sheets/1`, будущих `univer-docs/1` и `univer-slides/1` без дублирования таблиц журнала и без перестройки ядра БД.

---

## 3. Детализация инженерного укрепления (проверено по коду Vaulter)

### 3.1. Переход с 32-битного `hashtext()` на 64-битный `hashtextextended(?, 0)` (Задача `0.3`)
- В `packages/core/src/Database/VaulterDatabase.php` (`lockAggregateShared`, `advisoryLock`) и `packages/media/src/Cas/BlobLock.php` (`acquireMany`) заменить 32-битный `hashtext(...)` на 64-битный `hashtextextended(..., 0)` (сохранив `DISTINCT ... ORDER BY lock_key`).
- Синхронизировать фикстуры (`load/app/Console/ConcurrencyHashtext.php`, `ConcurrencyFixtureSupport.php`) и тесты `BlobLock`; заблокировать `hashtext(` и `pg_advisory_lock(` в `spaze/phpstan-disallowed-calls`.

### 3.2. Устойчивость `If-Match` / `ETag` за прокси (Nginx / Cloudflare) и жизненный цикл `Idempotency-Key` (Задача `0.3`)
1. **Proxy-safe `ETag` / `If-Match`:**
   - На ответах с сильным `ETag` (`packages/core`, `packages/drive`, `packages/documents`) выставлять сильный `ETag` в кавычках, дублировать его в заголовке **`X-Vaulter-ETag`**, добавить `'etag' => $this->etag()` в `DocumentContentResource::toArray()`.
   - В `ApplyResponseCachePolicy` выставлять **`Cache-Control: private, no-cache, no-transform`** и `Vary: Authorization, Accept` (`no-transform` запрещает Cloudflare/Nginx пережимать ответ и ослаблять `ETag` → `W/"..."`).
   - В CORS-ответах гарантировать экспонирование **`Access-Control-Expose-Headers: ETag, X-Vaulter-ETag, Retry-After, Idempotent-Replayed, X-Vaulter-Api-Version`** (точное имя заголовка реплея в коде `ReadIdempotencyKey.php` — **`Idempotent-Replayed`**) и разрешение **`Access-Control-Allow-Headers: If-Match, X-Vaulter-If-Match, Idempotency-Key, Authorization, Content-Type`**.
   - Сервер принимает `If-Match` (и опциональный резервный `X-Vaulter-If-Match` на случай поломки прокси), строго отвергая слабый тег `W/"..."` с `412 Precondition Failed` и кодом `weak_etag_not_allowed`.
   - `@vaulter/vue` читает тег в приоритете `body.etag → X-Vaulter-ETag → ETag` и отправляет строго сильный токен в кавычках.
2. **Жизненный цикл `Idempotency-Key` (`draft-ietf-httpapi-idempotency-key-header-09`):**
   - Сохранить существующие в `vaulter-core` сильные гарантии: HMAC-SHA256 `request_hash`, включение `actor_kind/actor_id` и `tenant` в `scope_digest`, и **обязательную перепроверку текущего ACL на каждом реплее** через `ReplayAuthorizer::authorize()` (после отзыва прав повтор возвращает `403`, а не сохранённый ответ!).
   - Ввести политику **7-дневного надгробия (`status = 'expired'` → `410 Gone`, RFC 9457)**: после истечения активного окна (24 ч) очищать `receipt_body`/`receipt_headers`, но сохранять компактную запись с `scope_digest` и `request_hash` ещё 7 дней, чтобы запоздалый повтор на 25-й час не выполнил мутацию повторно.
   - Для `multipart/form-data` вычислять `request_hash` по отсортированным полям и потоковым SHA-256 файлов без учёта случайного `boundary`.

### 3.3. Закрытие обхода SSRF вебхуков (Задача `0.3`)
- В `packages/webhooks/src/DefaultOutboundUrlGuard.php`:
  - На PHP 8.5 использовать **`Uri\WhatWg\Url`** (на PHP 8.4 — строгий разбор + `idn_to_ascii(..., IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)`), а **не** `Uri\Rfc3986\Uri` (который не выполняет IDNA/Punycode и пропускает `userinfo`).
  - Явно запрещать `userinfo` (`https://trusted.com@169.254.169.254`), завершающую точку хоста (`str_ends_with($host, '.')`), неразрешённые порты и схемы; проверять все A/AAAA записи (с свёрткой `::ffff:a.b.c.d`) fail-closed.
- В `PinnedOutboundEndpoint` и `DeliverWebhook`:
  - Добавить `curlConnectToEntry(): string` формата `"{$host}:{$port}:{$ipOrBracketedIpv6}:{$port}"` (квадратные скобки `[ip]` для IPv6; сохраняет оригинальный TLS SNI и заголовок `Host`).
  - Перевести `DeliverWebhook` на **`CURLOPT_CONNECT_TO`** и добавить **`CURLOPT_FORBID_REUSE => true`** (исключает повторное использование TCP-сокета пулом Guzzle после `curl_reset()`), сохранив `CURLOPT_FOLLOWLOCATION => false` и `curlResolveEntry()` как deprecated для BC.
  - События оформлять по спецификациям **Standard Webhooks 1.0** (`webhook-id`, `webhook-timestamp`, `webhook-signature: v1,...` с мульти-подписью при ротации `whsec_`) и **CloudEvents 1.0**.

### 3.4. PostgreSQL 18.7 / 19.0: миграции, конкурентность и защита от `#61964`
1. **Breaking changes PG 18.7 → PG 19.0 (учитывать в CI-конфигах и ранбуках):**
   - Удалён `io_workers` → заменить на 4 параметра (`io_min_workers`, `io_max_workers`, `io_worker_idle_timeout`, `io_worker_launch_interval`).
   - Удвоить пользовательские значения `max_locks_per_transaction` (дефолт вырос с 64 до 128).
   - `json_array()` на пустой выборке возвращает `'[]'` вместо `NULL`; `standard_conforming_strings = on` обязателен.
   - `ON DELETE RESTRICT` возвращает `SQLSTATE 23001` (`restrict_violation`) наряду с `23503` — обрабатывать оба кода.
   - `GENERATED ALWAYS AS (...)` виртуальны по умолчанию — для индексируемых колонок явно писать `STORED`.
2. **Запрет `REPACK (CONCURRENTLY)` на горячих таблицах:** в PG 19 `REPACK (CONCURRENTLY)` **не MVCC-безопасен** (открытые до старта снимки видят таблицу пустой после завершения) и не работает на партиционированных таблицах. Для `v_outbox` и `v_outbox_deliveries` использовать агрессивный autovacuum (`autovacuum_vacuum_scale_factor = 0, autovacuum_vacuum_threshold = 2000`) и/или партиционирование по диапазону в высоконагруженных PG-профилях без ломки `outbox_seq` и gap-reconciliation.
3. **Защита от бага Laravel `#61964` и `DEFERRABLE` FK:** все отложенные FK в `scripts/ci/sql/schema/` объявляются как **`DEFERRABLE INITIALLY IMMEDIATE`** (вместо `INITIALLY DEFERRED`). Внутри `VaulterDatabase::transaction()` перед `commit()` на PG выполнять `SET CONSTRAINTS ALL IMMEDIATE` (при использовании отложенных ограничений), а в блоке catch/finally гарантировать очистку открытой PDO-транзакции (`$pdo->inTransaction()` → `rollBack()`) и флага `$attempt->aborted = true` даже при не-concurrency ошибках `COMMIT`.
4. **`clock_timestamp()` и `uuidv7()`:** для проверки сроков аренды (`lease_until` / `expires_at`) после ожидания блокировок использовать только `clock_timestamp()` (не замороженный `now()`), передавая вычисленное значение параметром/скалярным подзапросом, чтобы `VOLATILE` не ломал index range scan. Первичные ключи `uuidv7()` (в закрытых PG-таблицах генерируются на сервере БД через `DEFAULT uuidv7()` во избежание клиентского clock skew) **запрещено** использовать в качестве секретных токенов доступа (раскрывают timestamp с точностью ~0.25 мкс).
5. **PG 19 `WAIT FOR LSN` для read-replicas:** при чтении реплики с `after_receipt` выполнять строго **верхнеуровневой** командой (до старта транзакции и без удержания локов): `WAIT FOR LSN 'lsn' WITH (MODE 'standby_replay', TIMEOUT '120ms', NO_THROW)`; при `timeout` или `not in recovery` откатываться на Primary.

### 3.5. Профиль изоляции мультитенантности (PostgreSQL RLS + PgBouncer + Octane)
- **Разделение ролей:** миграции — `vaulter_migrator`; приложение — `vaulter_runtime` без `SUPERUSER` и `BYPASSRLS`; все `VIEW` — `WITH (security_invoker = true)`.
- **Fail-closed RLS политики с InitPlan-кешированием:** на тенант-таблицах включаются `ENABLE` + `FORCE ROW LEVEL SECURITY`, политики создаются `AS RESTRICTIVE` с **обязательным скалярным подзапросом `(SELECT ...)`**, чтобы планировщик вычислял `current_setting()` один раз на запрос (InitPlan), а не на каждую строку:
  ```sql
  USING (tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')::bigint))
  WITH CHECK (tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')::bigint))
  ```
  (тип приведения соответствует стратегии ключа тенанта хоста).
- **PgBouncer (`pool_mode = transaction`, версия `>= 1.21` / `1.24+`):** обязательно задать **`max_prepared_statements = 200`** в `pgbouncer.ini` и **запретить `PDO::ATTR_EMULATE_PREPARES => true`** (портит типы PG). Транзакционный контекст `SELECT set_config('vaulter.tenant_id', ?, true)` выставляется централизованно в `VaulterDatabase::transaction()`.
- **Octane / Horizon:** нулевое состояние запроса/тенанта в `singleton()`, передача метаданных через `Illuminate\Support\Facades\Context` и `RestoreTenantContext`, обязательный префикс тенанта в ключах Redis/кеша.

### 3.6. Кросс-облачный S3 карантин, ClamAV `>= 1.5.5`, сегментированный `secretstream` и `ZipCentralDirectoryGuard`
- **Дедупликация CAS:** `dedup_scope = 'tenant'` по умолчанию (защита от Confirmation-of-a-File и обеспечение per-tenant крипто-удаления).
- **Кросс-облачный S3 / R2 / Yandex OS / SeaweedFS карантин:** прямые загрузки идут в одноразовый ключ `quarantine/{tenant_id}/{upload_uuid}` версионированного бакета (правило автоочистки брошенных частей 24 ч). **Не отправлять `x-amz-checksum-sha256` на `UploadPart`** (Cloudflare R2 возвращает `HTTP 501 NotImplemented`); для частей использовать `Content-MD5`, `CompleteMultipartUpload` выполнять сервером PHP с `If-None-Match: *` (фиксируя `ETag` + `VersionId` в `v_blobs.storage_version`), а истинный SHA-256 вычислять потоково одновременно со сканированием.
- **ClamAV `>= 1.5.5` (закрыты CVE-2026-76422/76411/76421/76430):** строго внешний демон `clamd` по Unix-сокету/изолированной сети через протокол `zINSTREAM\0` чанками 64 КиБ (лимит RAM PHP <= 64 МБ на файле 1 ГиБ). Обязательный конфиг `clamd`: `StreamMaxLength 1024M`, `MaxScanSize 1024M`, `MaxFileSize 512M`, `MaxRecursion 16`, `AlertEncrypted yes`, `AlertBrokenExecutables yes`. Проверка версии `clamd >= 1.5.5` включается в `vaulter:doctor`. Политика строго fail-closed.
- **Сегментированное конвертное шифрование (опциональный профиль с поддержкой O(1) HTTP Range):** вместо единого неперематываемого потока файл шифруется независимыми сегментами по **4 МиБ** (64 чанка × 64 КиБ) через `sodium_crypto_secretstream_xchacha20poly1305_*`:
  - обёртка DEK в KMS тенанта с AAD `"dek:{$tenantId}:{$blobSha256}"`;
  - субключ сегмента `segKey = sodium_crypto_generichash(pack('P', $segIdx), $dek, 32)`;
  - AAD каждого чанка `"vaulter:v1:{$tenantId}:{$blobSha256}:seg={$segIdx}"` (криптографически исключает подмену блобов между тенантами) + `TAG_FINAL` на последнем чанке сегмента; немедленный `sodium_memzero` после операции. Оверхед ~278 КиБ на 1 ГиБ (0.026%), поддержка произвольного HTTP `Range` за O(1).
- **Единый барьер архивов (`ZipCentralDirectoryGuard`):** используется в `MimeSniffer`, `vaulter-sheets` (`4.3`) и `vaulter-editor-pro` (`10.1–10.2`) с жёсткими квотами из §2 №8, проверкой фактического числа распакованных байт при чтении и блокировкой `<!DOCTYPE`, `<!ENTITY`, `XInclude` и всех не-гиперссылочных `TargetMode="External"` (`attachedTemplate`/`.dotm`, внешние `image`/UNC, `oleObject`/`ms-msdt:`).

### 3.7. Закрытие документационного долга
- В `vaulter` (задача `0.3`): зафиксировать статус пропущенного ADR `0015` в `docs/adr/index.md` и расширить короткие (< 1 000 байт) ранбуки в `docs/guides/operations/` до полных процедур диагностики и восстановления.
- В `vaulter-collaboration` (задача `2.0`): заменить 217-байтовые заглушки полноценными `docs/guides/collaboration.md` и `docs/guides/hub.md` (EN + RU), покрывающими Room JWT, `protocol=2`, бинарное хранение Yjs, compaction lease, epoch fencing (`4409`/`4412`), отзыв прав и строгий профиль `3.5`.

### 3.8. SLO-бюджеты производительности и OpenTelemetry (2026)

| Контур / Сценарий | Целевой p50 | Целевой p95 | Целевой p99 | Бюджет памяти / ограничения |
| --- | --- | --- | --- | --- |
| **Collab 50 КБ**, 1–2 автора, async (`flushDelay: 80ms`) | <= 8 мс ACK / <= 12 мс peer | <= 18 мс / <= 25 мс | <= 35 мс / <= 45 мс | <= 8 МБ/комната, ретраи 0% |
| **Collab 50 КБ**, 1–2 автора + AI, **strict** | <= 22 мс / <= 28 мс | <= 55 мс / <= 65 мс | <= 110 мс / <= 125 мс | <= 12 МБ/комната, ретраи < 0.5% |
| **Collab 500 КБ**, 5 авторов + AI, **strict** | <= 45 мс / <= 55 мс | <= 125 мс / <= 140 мс | <= 240 мс / <= 270 мс | <= 42 МБ/комната, ретраи < 2.5%; потолок strict: <= 15–20 авторов; `encodeStateAsUpdateV2` выносится из мьютекса на клоне |
| **Univer Node 26 Runtime** (100k ячеек / 10k формул) | — | <= 250 мс open; <= 500 мс FORCED calc (< 180 мс warm с патчем `#7936`); <= 200 мс save+canon; **<= 950 мс общий холодный цикл** | — | Пул `child_process.fork`, `--max-old-space-size=640`, рестарт воркера каждые 50 задач |
| **Laravel 13 Octane** (API + RLS) | 8–15 мс | 30–65 мс | — | 220–310 МБ RAM/воркер; оверхед OTel `< 2 мс` на p95 |
| **CAS Upload + ClamAV `zINSTREAM\0`** (файл 1 ГиБ) | — | — | — | <= 64 МБ RAM на PHP-воркер |

**Правила OpenTelemetry:**
- PHP: `ext-opentelemetry >= 1.4.2` (`1.5.0`) + нативное `ext-protobuf` (установка через **PIE** `php/pie` или `install-php-extensions`, PECL устарел) + `open-telemetry/sdk ^1.16.1`. Экспорт строго асинхронный OTLP HTTP/protobuf на loopback sidecar / DaemonSet Collector (при > 1 000 traces/s — Tail Sampling). В Collector использовать встроенный батчинг экспортера (отдельный `batch` processor устарел).
- Node.js 26: OTel JS SDK `^3.0.0` (`@opentelemetry/api ^1.10.0`).
- **Атрибуты и корреляция:** собственные атрибуты строго в пространстве **`vaulter.*`** (пространство `app.*` запрещено для серверного кода). В долгоживущих WebSocket Hocuspocus передавать `traceparent` на **каждую логическую мутацию** (не привязывать к многочасовому спану HTTP 101 Upgrade); в Outbox Relay использовать **Span Link** к породившей транзакции.

---

## 4. Сводная матрица лицензионной чистоты и принятые архитектурные решения

### 4.1. Утверждённые production-зависимости (100% пермиссивные)

| Слой | Разрешённые пакеты и версии | Лицензия | Запрещённые альтернативы |
| --- | --- | --- | --- |
| PHP Core / AI / Admin | `laravel/framework ^13.38`, `laravel/ai ^1.3.0`, `neuron-core/neuron-ai ^2.8.11`, `filament/filament ^5.11.4`, `livewire/livewire ^4.4.0`, `laravel/octane ^2.12.1`, `league/flysystem ^3.29`, `maennchen/zipstream-php` | MIT / Apache-2.0 | `dunglas/mercure` (AGPL), Filament Blueprint, нестабильный pre-1.0 `prism-php/prism` |
| Realtime / SSE / Storage | `centrifugal/centrifugo >= 6.9.0`, `SeaweedFS` (или внешнее S3), внешний демон `clamd >= 1.5.5` (IPC) | Apache-2.0 (+ внешний ОС-демон) | `dunglas/mercure` (AGPL), встроенный `minio/minio` (AGPL), `centrifugo < 6.9.0`, линковка `libclamav` (GPL) |
| Vue / Tiptap / Collab | `vue >= 3.5.42` (`3.5.46`), `@tiptap/* >= 3.34.0`, `@tiptap/y-tiptap ^3.0.10`, `y-prosemirror ^1.3.7`, `prosemirror-changeset ^2.4.4`, `yjs >= 13.6.33`, `lib0 >= 0.2.119`, `@hocuspocus/server >= 4.9.0` | MIT | `registry.tiptap.dev` / `@tiptap-pro/*`, `@y/prosemirror` (нестабилен) |
| Sheets / XLSX / DOCX | `@univerjs/* 1.5.2` (единая версия), `ironcalc 0.9.1`, `fflate 0.8.3`, `saxes 6.0.0`, `fast-xml-parser >= 5.3.5`, `yauzl ^3.4.0`, `docx 9.16.0` | Apache-2.0 / MIT / ISC | `@univerjs-pro/*`, `@univer-cli/*` (`#7747`), `handsontable`, `hyperformula` (GPLv3), `xlsx@0.18.5` (SheetJS), `exceljs`/`unzipper` (`buffers@0.1.1`), `mammoth` |
| Telemetry & CI (`dev`) | `ext-opentelemetry 1.5.0`, `open-telemetry/sdk 1.16.1`, OTel JS `3.0.0`, `phpstan ^2.3.6`, `larastan ^3.15.0`, `spaze/phpstan-disallowed-calls ^4.15.1`, `pest ^4.7.2/^5.3.1`, `roave/bc-check ^8.23.0`, `@microsoft/api-extractor ^7.60.1`, `dedoc/scramble ^0.13.55`, `cosign >= 3.0.2` | MIT / Apache-2.0 / ISC | Docker Notary v1 (отключается 08.12.2026) |

### 4.2. Согласованные архитектурные границы (что сохраняем неизменным в ядре Vaulter)
1. **Структура пакетов ядра (`axiomasoft/vaulter-core`)** и **5-репозиторная топология** (фазы `00–10`) сохраняются: ядро не дробится на отдельные микропакеты и не переименовывается; 3-СУБД матрица (PG/MySQL/SQLite) и `IdStrategyResolver` в публичном `vaulter` сохраняются.
2. **Единый `VaulterDatabase::transaction()`** остаётся точкой входа транзакций (в него встраиваются PG RLS-хук, `SET CONSTRAINTS ALL IMMEDIATE` и защита от `#61964`, вместо создания отдельного `TenantAtomicRunner` с захардкоженным `UuidInterface`).
3. **Существующие `v_outbox` / `v_outbox_deliveries` (`outbox_seq` + gap-reconciliation)**, `v_nodes` + `lockAggregate('drives', $id)` и `v_idempotency_keys` (`ReplayAuthorizer` + HMAC-SHA256 + `Idempotent-Replayed`) сохраняются и усиливаются (добавляется 7-дневное надгробие `expired` → `410 Gone`, запрет `REPACK` на горячих таблицах и настройка autovacuum).
4. **Единый протокольно-нейтральный `vaulter-collaboration`** (`v_collaboration_heads.protocol`, `v_collaboration_updates`, `v_collaboration_receipts`, `v_collaboration_client_ids` с разделением по `2^31`) остаётся каноническим именем таблиц и маршрутов `collaboration/hub/*` для всех типов документов (`yjs/2`, `univer-sheets/1`, будущих `univer-docs/1`/`slides`).
5. **Выдача `new_row_ids` в `vaulter-sheets`** остаётся на стороне PHP-владельца (`workbook.tables`), а неизвестные `resources` допускают passthrough только для некоординатных операций (для структурных правок вроде `insert_row_before` сохраняется fail-closed `unsupported_content`).
