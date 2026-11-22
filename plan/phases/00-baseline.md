# 0. Исходное состояние

Код Vaulter `b230f6e27`. [Файлы и сборка](../spec/implementation/vaulter.md) · [Стенд базовой установки](../spec/base-install.md) · [Стенд PostgreSQL](../checks/schema.sh) · [Инженерные стандарты и стоп-краны CI](../spec/engineering-standards.md).

### 0.1 Поставляемый редактор

**После** — нет.

1. Использовать рабочее дерево с этим комплектом `plan/` и доступной ревизией Vaulter `b230f6e27`. Рядом требуется Packman `a5a5442` или путь в `PACKMAN_DIR`. Стенд извлечёт код по обеим ревизиям, соберёт ZIP метапакета и четырёх модулей и npm-архив Vue. Сохранить обе полные SHA, SHA-256 архивов, lock и перечень зарегистрированных типов.
2. На изолированном Laravel выполнить создание, два параллельных чтения, запись первой вкладки, устаревшую запись второй и попытку записи читателем. Команда из корня Vaulter — `bash plan/checks/flexcms/run.sh`. Каталог с логами печатает стенд. [Точные зависимости](../spec/base-install.md)

- **0.1-A** Чистый потребитель запускается; HTTP 201 → 200 → 412 → 403, после повторного открытия сохранён первый текст и во второй вкладке доступен локальный черновик.
- **0.1-B** Зафиксированы исходный экспорт VaulterSpreadsheet и отсутствие Yjs/закрытого клиента в графе базовой установки. Эти данные сравниваются с 1.2.

### 0.2 Версии схемы и эталоны

**После** — нет.

1. Выполнить `python3 plan/checks/verify-fixtures.py`. Зафиксировать состав трёх корпусов. Синтетический договор и бюджет в SDK независимы от книги «Яблоки/Груши».
2. Сверить текущую схему каждого пакета с физическим снимком. Выполнить отдельно `bash plan/checks/schema.sh` для уже приложенного целевого SQL в изолированном PostgreSQL. Laravel-миграции целевой версии исполнитель создаст в задачах соответствующих пакетов. Зафиксировать отличия пустых комплектаций и список FK. [База данных](../spec/database/README.md)
3. Сравнить полное и адресное чтение на одном корпусе из комплекта проверок. [Методика и команда запуска](../spec/storage.md)

- **0.2-A** На исходных данных проходят 34 пары вызовов, сценарии аварий живой комнаты и формульный корпус. Искажённый digest либо подмена формулы проваливают соответствующую проверку.
- **0.2-B** Текущие таблицы сопоставлены с владельцами; целевой DDL разворачивается в новом кластере независимо от Laravel. Целевые DDL (включая 0 неиндексированных FK по `unindexed-fks.sql`) и отработавший benchmark имеют раздельные результаты.

### 0.3 Инженерное укрепление базы, безопасность и стоп-краны CI

**После** — 0.1, 0.2.

1. Перевести PostgreSQL advisory locks в `packages/core/src/Database/VaulterDatabase.php` (`lockAggregateShared`, `advisoryLock`) и `packages/media/src/Cas/BlobLock.php` (`acquireMany`) с 32-битного `hashtext()` на 64-битный транзакционный `hashtextextended(?, 0)`, синхронизировать тесты и фикстуры (`load/app/Console/ConcurrencyHashtext.php`, `ConcurrencyFixtureSupport.php`). Добавить обработку `SQLSTATE 23001` (`restrict_violation` в PostgreSQL 18+) наряду с `23503`, а в `VaulterDatabase::transaction()` гарантировать `SET CONSTRAINTS ALL IMMEDIATE` перед `commit()` и очистку PDO/`afterCommit` при ошибке на `COMMIT` (защита от бага Laravel `#61964`).
2. Закрыть уязвимость прокси, идемпотентности, SSRF и ZIP: (a) в HTTP-ответах `core`/`drive`/`documents` дублировать сильный `ETag` в заголовке `X-Vaulter-ETag`, выставлять `Cache-Control: private, no-cache, no-transform` и `Vary: Authorization, Accept`, добавить `'etag'` в тело `DocumentContentResource`, экспонировать `Access-Control-Expose-Headers: ETag, X-Vaulter-ETag, Retry-After, Idempotent-Replayed, X-Vaulter-Api-Version` и `Access-Control-Allow-Headers` (`If-Match`, `X-Vaulter-If-Match`, `Idempotency-Key`), возвращать различимый `412` на слабый `If-Match: W/"..."`, поддержать приоритет `body.etag → X-Vaulter-ETag → ETag` в `@vaulter/vue` и 7-дневное надгробие `expired` → `410 Gone` для `Idempotency-Key`; (b) в `packages/webhooks` (`DefaultOutboundUrlGuard`, `PinnedOutboundEndpoint`, `DeliverWebhook`) перейти на `Uri\WhatWg\Url` (PHP 8.5) / `idn_to_ascii` (PHP 8.4), запретить `userinfo` и trailing-dot хосты (`example.com.`), перевести пиннинг IP на `CURLOPT_CONNECT_TO` (с `[ipv6]`) + `CURLOPT_FORBID_REUSE => true`; (c) в `packages/drive/src/Uploads/Inspection/MimeSniffer.php` внедрить `ZipCentralDirectoryGuard` (запрет `S_IFLNK`, `..`, `\`, `\0`, дубликатов и zip-бомб без `extractTo()`).
3. Ввести постоянные механические стоп-краны CI по [engineering-standards.md](../spec/engineering-standards.md) (`spaze/phpstan-disallowed-calls ^4.15.1` на запрет `DB::transaction()`, `set_config(..., false)`, `hashtext(`, `pg_advisory_lock(`, `parse_url(`, `ZipArchive::extractTo()`, `VerifyCsrfToken::class`, `env()` вне `config/`; сканер лицензий CI; guard `@phpstan-ignore`/`->skip()`; проверка `PG 18.7 + PG 19.0`, `Node 26.13.1` с `libatomic`/`libstdc++` и `ClamAV >= 1.5.5` в `vaulter:doctor`). Закрыть документационный долг в `vaulter`: оформить статус ADR `0015` в `docs/adr/index.md` и развернуть короткие ранбуки (< 1 000 байт) в `docs/guides/operations/`.

- **0.3-A** В `packages/*/src/` отсутствуют вызовы 32-битного `hashtext(` и сессионного `pg_advisory_lock(`; тесты блокировок `core`/`media`/`drive` проходят на PostgreSQL 18+, маппер БД распознаёт `SQLSTATE 23001` и `23503`, а `plan/checks/schema.sh` подтверждает 0 неиндексированных FK.
- **0.3-B** Интеграционный тест с обратным прокси (ослабляющим `ETag` до `W/"..."` при gzip) и CORS подтверждает успешную запись `@vaulter/vue` по `X-Vaulter-ETag`/`etag` и отказ `412` на устаревший или слабый `If-Match`; регрессионный тест `webhooks` блокирует `https://example.com./hook` и DNS Rebinding через `CURLOPT_CONNECT_TO`; тест `MimeSniffer` отвергает ZIP с симлинком `S_IFLNK`.
- **0.3-C** Механические CI-стоп-краны (PHPStan disallowed-calls, Arch-тесты, guard подавлений) гарантированно падают на тестовых нарушениях и проходят на чистом дереве; ADR `0015` зафиксирован в индексе, все операционные ранбуки в `docs/guides/operations/` содержат полные процедуры диагностики и восстановления.
