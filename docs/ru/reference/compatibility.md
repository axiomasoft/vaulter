---
title: Совместимость API
description: Что покрывает обещание совместимости Vaulter 1.0 — операции, снимки, enum'ы, исключения, события, ключи конфигурации и путь обновления — и что оно не покрывает (модели, Actions, таблицы).
outline: deep
---

# Совместимость API

С версии 1.0 Vaulter следует [семантическому версионированию](https://semver.org/lang/ru/). На этой
странице — что именно входит в обещание. До 1.0 ничего не заморожено: переименования и удаления
делаются сразу, и каждое описано в
[`UPGRADE.md`](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) (см.
[ADR-0020](/adr/0020-authorized-public-api) и [ADR-0026](/adr/0026-public-api-snapshots)).

## Что входит в публичный контракт

| Поверхность | Обещание |
| --- | --- |
| Операции и handle | Интерфейсы `Vaulter\Contracts\**` (`DriveHandle`, `NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`, `AccessOperations`, `ShareOperations`, `ActivityOperations`), фасад `Vaulter` и операции документов. Имена методов, **имена параметров** (вызывающие используют именованные аргументы), типы параметров и результатов. |
| Снимки и страницы | `NodeSnapshot`, `FileRevisionSnapshot`, `GrantSnapshot`, `ShareLinkSnapshot`, `ActivityEntrySnapshot`, `DriveSnapshot`, `QuotaSnapshot`, `MintedShareLink` и `Page<T>`: их свойства, **только для чтения**. Конструкторы помечены `@internal`: снимки создаёт Vaulter, хост — никогда. |
| Enum'ы | Публичные enum'ы помечены `@closed` (набор значений фиксирован: новое значение — MAJOR) или `@extensible` (клиент обязан обрабатывать неизвестное значение). Неизвестный `NodeKind` или тип документа трактуйте как «только скачать». |
| Исключения | Классы и машинные коды (`node_not_found`, `quota_exceeded`, …) из раздела [Ошибки](/ru/guides/errors). Код не меняется; новый код — MINOR. |
| События | Классы Laravel-событий и их публичные поля. |
| Конфигурация | Ключи `config/vaulter.php` и конфигов других пакетов. Устаревший ключ работает через нормализатор до следующего MAJOR. |
| Путь обновления | Миграции обновления выполняются по порядку с любой схемы 1.x; после 1.0 миграции только аддитивные (expand, migrate, contract). |
| REST | Маршруты и JSON версии API из URL. v1 замораживается с её выходом (см. [REST](/reference/rest-api)). |

Изменение — **MAJOR**, если оно убирает или переименовывает что-то из таблицы, сужает тип параметра,
расширяет тип результата, на который может опираться вызывающий, меняет машинный код или убирает значение
enum'а. Необязательный параметр, метод у результата, новый код исключения, новое значение `@extensible`
enum'а, ключ конфигурации или событие — **MINOR**.

Так добавляется новый ключ конфигурации, например бюджет завершения загрузки
`vaulter-drive.uploads.resumable.complete_timeout_seconds` (`VAULTER_UPLOAD_COMPLETE_TIMEOUT_SECONDS`,
целое 1..300, по умолчанию 60, PLAN4-D19). Он ограничивает один запрос завершения вместе с повторами БД;
при исчерпании завершение отклоняется с `upload_conflict` (409), ничего не публикуется, staged-байты
остаются для повтора.

Бюджет применим только на локальном staging-диске. Резюмируемое завершение (`tus`, `s3-multipart` — имена протоколов, а не возможности хранилища) на удалённом, S3 или непрозрачном кастомном диске **не поддерживается**: оно отклоняется с `upload_conflict` (409) до любого обращения к хранилищу. Заметка об обновлении: размещайте резюмируемые загрузки на локальном диске; одноразовая `direct`-загрузка и content-addressed store на S3 не затронуты. Сессия, закоммиченная до этого изменения, может выполнять отложенную очистку staged-диска без жёсткого дедлайна (известное ограничение, runbook владельца P8.2).

### I13: текущая PHP-поверхность

После P6.3 каталог PHP API/SPI строится по текущим исходникам: I13 работает в block-mode без временных исключений и без постоянных host-input entries. Readonly результаты включают `UploadSessionSnapshot`, страницы изменений и вложенные snapshot-факты; события передают snapshots и скалярные факты вместо доменных ORM-моделей.

`NodeSnapshotContributor` остаётся поддерживаемым implement-SPI: `contribute()` получает `list<NodeSnapshot>` без моделей, до добавления фрагментов, viewer state и reader key. Собственные агрегаты загружаются по id узлов. `HasDrives` остаётся доступным trait, но его ORM-связь `drives()` внутренняя; поддерживаемый список — `Vaulter::drives($actor)` с результатом `Page<DriveSnapshot>`. ORM-геттеры конфигурации и нативные factories внутренние; ключи конфигурации остаются действующими. Общая BC-квалификация и общий guard/corpus относятся к P6.2; обновлённый каталог не объявляет их пройденными.

## Пакеты и версии

Все PHP-пакеты выходят с одной версией и одним тегом, и каждый split-репозиторий
(`axioma-studio/vaulter-core`, `-media`, `-drive`, `-documents`, `-webhooks`, `-filament` и метапакет
`axioma-studio/vaulter`) получает один и тот же тег. Пакеты требуют друг друга как `self.version`,
поэтому смешать версии нельзя. `axioma-studio/vaulter-azgard` выходит только после тега самого AzGuard;
до тех пор он исключён из релиза. У `@vaulter/vue` своя semver-линия, а поддерживаемую линию REST API
он объявляет в `package.json` (`"vaulter": { "api": "1" }`). Состав релиза — в `release/packages.json`.

## Что не входит

- **Eloquent-модели** (`Vaulter\Models\**`), их колонки, связи и scope'ы. Они `@internal`: операции
  никогда их не возвращают, а запись модели вне операции отклоняется (см. ниже).
  `DriveHandle::model()` нужен пакетам Vaulter и тестам.
- **Actions, Queries, репозитории** и всё остальное в `src/`, что не названо выше.
- **Таблицы и колонки.** Схема стабильна (после 1.0 — только аддитивные миграции), но доступ к ней —
  через PHP API; см. [Совместимость с БД](/ru/reference/compatibility).
- Любой класс или метод с `@internal` в докблоке и всё в пространствах имён `Testing`.

## Защита записи

Модели Vaulter отклоняют `save()`, `delete()` и массовые `update`, `delete`, `insert`, `upsert`,
`increment` вне операции Vaulter и бросают `WriteOutsideOperationException`
(`write_outside_operation`, см. [Ошибки](/ru/guides/errors#write-outside-operation)). Это вторая линия
защиты за инвариантами в Actions, а не песочница: `DB::table()` и сырой SQL не перехватываются.
`vaulter.database.write_guard = false` (`VAULTER_DB_WRITE_GUARD=false`) — только если вашему коду
нужно время на переход.

## Как писать код, переживающий обновления

```php
$drive = Vaulter::drive($project)->actingAs($user);

$report = $drive->files()->upload($source);                    // NodeSnapshot
$page = $drive->nodes()->children($report->parentId);          // Page<NodeSnapshot>
$drive->nodes()->rename($report->id, 'Q3.pdf', expectedSequence: $report->sequence);

foreach ($page->items as $node) {
    // читайте $node->id, ->name, ->kind … снимок не создают и не меняют
}
$next = $drive->nodes()->children($report->parentId, new ListOptions(cursor: $page->nextCursor));
```

- Передавайте `$node->id` (строку) или сам снимок; идентификаторы сравнивайте как строки.
- Курсор непрозрачен: возвращайте `nextCursor` как есть.
- Обрабатывайте неизвестные значения enum'ов и `null` в `document` у узла с недоступным типом.

## Совместимость с базами данных

Vaulter — **Postgres-first, переносим на MySQL и работает на SQLite**. Схема — это один
общий набор таблиц `v_*` (см. [Storage Panels](/ru/concepts/drives-and-profiles)); в ней нет
ничего, что было бы завязано на конкретный движок, но несколько фич раскрываются только на
настоящем сервере. На этой странице разобрано, что даёт каждый движок и какие немногочисленные
Postgres-специфики закладывает схема.

### Каноническая схема и обновление

Устанавливайте 1.0 на пустую базу. Каждый объявленный модуль устанавливает свои таблицы,
даже если отключён во время работы: одна create-миграция на таблицу, rollback удаляет её.
`vaulter.database.table_prefix` применяется ко всем таблицам; отдельные переопределения
имён таблиц не поддерживаются. Перехода с 0.x, preflight и backfill данных нет. См.
[руководство по обновлению](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md).

### Целостность головы агрегата

Голова файла (`v_files.current_revision_id`) и голова документа (`v_documents.current_revision_id`)
обязаны указывать на ревизию этого же агрегата. Гарантия зависит от движка:

| Движок | Целостность головы |
|---|---|
| **PostgreSQL**, **MySQL** | Составные внешние ключи `v_files_head_fk` / `v_documents_head_fk`: `(current_revision_id, id) → revisions(id, file_id \| document_id) ON DELETE RESTRICT`. Чужую голову и удаление головы отвергает сама БД. |
| **SQLite** | Внешнего ключа головы нет: SQLite не умеет добавлять ограничение к таблице, созданной раньше таблицы ревизий. Инвариант держат Actions Vaulter (и тесты целостности головы); записи в обход Actions не проверяются. |

Purge снимает голову до удаления строки узла: MySQL проверяет RESTRICT-ключ во время каскада
узел → агрегат → ревизии, пока строка агрегата ещё существует. Таблицы и колонки — не публичный
API: меняйте данные Vaulter только через его PHP API.

### Поддерживаемые движки

| Движок | Статус | Для чего использовать |
|---|---|---|
| **PostgreSQL 18** | **Рекомендуется.** CI квалифицирует эту версию по всем стратегиям идентификаторов. | Прод. Всё, что ниже, работает нативно. |
| **SQLite** | Полностью поддерживается. Дефолтная полоса тестов идёт на in-memory SQLite. | Локальная разработка, тесты, небольшие однофайловые установки. |
| **MySQL 9.7 LTS** | Поддерживается. CI квалифицирует эту версию. Схема переносима. MariaDB в обязательную матрицу не входит: сверяйте целевую версию отдельно. | MySQL-хосты на квалифицированной линии. |

::: tip PHP-расширение
Postgres требует расширение `pdo_pgsql`. Оно есть в большинстве дистрибутивов PHP; на голом
образе установите его (например, `docker-php-ext-install pdo_pgsql`), прежде чем направлять
Vaulter на Postgres.
:::

### Почему Postgres — рекомендуемая полоса

Несколько частей Vaulter на SQLite превращаются в **no-op** и делают свою настоящую работу
только на сервере — их прогоняют на Postgres в CI, чтобы они не регрессировали:

- **Безопасность контент-адресуемых блобов.** Безгоночный коммит блоба и разрушающий
  сборщик мусора выполняют критическую секцию под `pg_advisory_xact_lock` + `FOR UPDATE`, а
  проход сборки мусора идёт в режиме `SERIALIZABLE` с ретраем на ошибке сериализации. На SQLite
  всё это сворачивается до гарантии единственного писателя. См. [Хранилище и блобы](/ru/concepts/storage-and-blobs).
- **Индексы containment по `jsonb`.** Поле `context` ленты активности и `content` версии
  документа — это `jsonb` на Postgres с индексами `gin (… jsonb_path_ops)` под containment-запросы;
  другие движки хранят обычный `json`/text и пропускают gin-индекс.
- **Бинарное состояние документа.** Чекпоинт конкретного протокола (`collab_state`) — бинарная колонка. Vaulter
  хранит её так, чтобы она без потерь ходила туда-обратно на любом движке, включая Postgres
  `bytea` (который иначе через обычный строковый bind обрезал бы бинарные данные на первом
  NUL-байте). Журнал обновлений коллаборации (`v_collaboration_updates.payload`) хранится так же.

Ничего из этого не требует настройки — направьте Vaulter на Postgres, и всё применится
автоматически.


В свежей схеме CHECK-ограничения требуют полноты и непустых полей сохранённой и pending-привязок
коллаборации, а также положительных целочисленных версий схемы. У документа с привязкой
может быть null checkpoint.

### Стратегия идентификаторов × движок

Стратегия первичного ключа настраивается (см.
[Конфигурация → стратегия идентификаторов](/ru/guides/configuration)). Все три работают на любом движке:

| Стратегия | Колонка | Примечания |
|---|---|---|
| `ulid` (по умолчанию) | `char(26)` | Сортируется лексикографически; совпадает с morph-ключами Corex/Azgard. |
| `bigint` | `bigserial` / auto-increment | Классические целочисленные ключи. |
| `uuid7` | нативный `uuid` на Postgres | Упорядоченные по времени UUID; использует нативный тип `uuid` в Postgres. |

При `bigint` первичные ключи стандартных моделей Vaulter возвращаются как PHP int, если помещаются в
целочисленный диапазон PHP. Беззнаковые значения MySQL выше `PHP_INT_MAX` остаются точными
десятичными строками. Идентификаторы в snapshots и REST-ресурсах — строки, поэтому значения
выше безопасного целочисленного диапазона JavaScript сохраняются без потерь.

Рекурсивное дерево папок (`v_nodes.parent_id → v_nodes.id`) — самоссылающийся внешний ключ, и
оно чисто мигрирует под всеми тремя стратегиями на любом движке.

### Уникальность имён не зависит от collation

Имена соседей сравниваются через `v_nodes.name_key` = NFC(полное свёртывание регистра(NFC(name))),
вычисляемый в PHP и сравниваемый побайтово: `COLLATE "C"` в индексе PostgreSQL, `BINARY` по
умолчанию в SQLite, `utf8mb4_bin` плюс сгенерированная колонка `sha2(name_key, 256)` в MySQL
(ключ varchar(1024) превышает лимит индекса в 3072 байта). Свёртывание следует версии Unicode
работающих PHP mbstring/intl; смена версии требует миграции пересчёта.

### Регистронезависимый поиск

Поиск по имени узла и документа **сворачивает регистр** (`lower(col) like lower(?)`), поэтому
запрос находит совпадение независимо от регистра на любом движке — включая Postgres, чей `like`
регистрозависим (в отличие от SQLite/MySQL). Настройка не нужна.

### Ключи хоста и гранты ролей Spatie

Колонки владельца, субъекта, автора, tenant и actor используют `ids.host_keys` (AD25),
независимо от идентификаторов сущностей Vaulter `ulid`, `uuid7` или `bigint`. Значение по
умолчанию `string` — это `varchar(64)`: UUID, ULID и целочисленные ключи хоста сохраняются
строками. Узкие типы и их отображение на движки описаны в
[настройке ключей хоста](/ru/guides/configuration#host-keys-ids-host-keys).

Свежие миграции создают колонки ключей хоста с настроенным типом. Задайте `ids.host_keys`
до установки; изменение конфигурации не меняет существующие колонки.

Внутренний read-only `KeyColumnInspector` сверяет колонки ключей хоста, REF и строки
фиксированной длины, объявленные модулями, с `ids.host_keys` и `ids.strategy`. Он сообщает
об отсутствующих колонках и расхождениях типов или длины без изменения базы; команда
doctor использует этот реестр для диагностики.

Если используется опциональный [драйвер прав `spatie`](/ru/extending/permission-drivers),
грант на узел для **роли** Spatie хранит её **первичный ключ** в `v_node_grants.subject_id`.
Тип хранения задаёт `ids.host_keys`, независимо от `ids.strategy`, как и для субъектов
пользователей и команд. Выберите тип ключа хоста, вмещающий ключ роли, и адресуйте грант
через `$role->getKey()`.

### Хранимые типы моделей

Модель Vaulter, чей тип пишется в полиморфную колонку (сегодня — цель строки активности), хранится под
постоянным алиасом, а не под именем класса: `vaulter.node` для узла и `vaulter.drive` для диска. Алиас не
меняется, когда класс переезжает между пакетами; morph map приложения его не переименовывает. `vaulter-drive`
при загрузке сливает оба алиаса в morph map Laravel (после вашей карты, не заменяя её записи и не вызывая
`enforceMorphMap()`), поэтому хосту, который включил enforce, записи для них не нужны (подкласс из
`vaulter-drive.models.*` наследует алиас).
Ваши собственные модели (владелец диска, субъект гранта, автор комментария) остаются под вашей политикой morph.

### Как прогнать свои тесты на Postgres

По умолчанию набор тестов пакета идёт на in-memory SQLite. Чтобы прогнать его против Postgres
(как это делает CI), направьте тестовое соединение на сервер стандартными переменными `DB_*`:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret \
vendor/bin/pest
```

Каждый `TestCase` мигрирует собственную схему на старте, поэтому запускайте по одной
schema-группе за вызов (задача CI `test-pgsql` именно поэтому разбивает их на шаги по схемам).

## Каталог публичного контракта

> PHP-источники обновлены при закрытии I13 в P6.3; каталог предварительный до P6.2 (BC-gate). Строка — не пройденная BC-проверка; «not_checked» означает, что машинного снимка нет и путь не выдуман.

| Поверхность | Источник | Обещание 1.x | Обнаружение | Квалификация |
|:--|:--|:--|:--|:--|
| PHP API (`#[Api]`) | `packages/<p>/tests/Arch/__snapshots__/surface.{txt,json}` | не удалять и не сужать типы, методы, параметры (имена параметров — API: именованные аргументы), константы, кейсы enum | машинный дрейф | консервативный BREAK-REVIEW guard (P6.2) |
| PHP SPI (`#[Spi]`) | те же снимки (`surface.json` создаёт P6.2; сейчас `surface.txt`) | не добавлять обязательные члены в implement-SPI; расширять новым интерфейсом или явным решением | машинный дрейф | квалификация владельца |
| Internal | `#[Internal]` / Internal-namespace | без обещаний | скан атрибутов | н/п |
| REST (публичный) | `openapi/vaulter-v1.yaml` | oasdiff breaking = ERR запрещён; включает confidential-курсоры и ограниченные страницы grants/shares (пейджинг до `next_cursor=null`, пустая страница с продолжением) | машинный дрейф (P6.2) | consumer-runtime |
| REST (приватный хаб) | `packages/collaboration/openapi/hub.yaml`; claim `protocol` | версия в claim; приватный, обещаний хостам в 1.x нет | машинный дрейф (P6.2) | квалификация владельца |
| Problem codes | `vaulter:errors`, `x-vaulter-problems` | коды не удаляются, статус не меняется | вывод команды | вручную / not_checked до P6.3 |
| События | каталог событий; машинной схемы payload пока нет | поля не удаляются; новые — опциональные; envelope версионируется | not_checked (нет машинного снимка) | вручную; путь схемы не выдуман |
| Конфиг / env / publish-теги | `tests/Repo/Arch/Fixtures/identities/*` | ключи не удаляются без alias-цикла | дрейф identities-config | квалификация владельца |
| Container bindings / tags | документированные bindings/теги в `register()` провайдера плюс явная container-contract фикстура; универсального снимка bindings нет (`identities-bindings.json` не существует) | документированные id и теги остаются резолвимыми | вручную / not_checked | владелец: ядро (`packages/core`), линия 1.x; P6.3 |
| Команды | identities-cli | имена, опции, exit codes | имена/опции — дрейф; exit codes not_checked | вручную |
| Схема БД | `packages/core/tests/Fixtures/Schema/expected/**` | только аддитивные миграции в 1.x; upgrade-фикстуры от 1.0 | дрейф фикстур | квалификация владельца |
| Каталог операций | `vaulter:operations`, `tests/Repo/Arch/MutationContractCoverageTest.php` | имена операций стабильны; второго ручного каталога нет | машинный дрейф | consumer-runtime |
| Ключи типов документов, viewer, протоколов | identities-protocol / ledger | ключи стабильны | машинный дрейф | квалификация владельца |
| Ключи/layout хранилища и версии целостности | вручную / not_checked (машинного снимка нет) | layout и версия целостности не меняются в 1.x без миграции | not_checked | владелец: `packages/media` (CAS), линия 1.x |
| Сериализованные задачи/receipt, retry/drain | вручную / not_checked | задачи и receipt из 1.x читаются последующими 1.x; семантика retry/drain неизменна. Прохождение проверки сигнатур не квалифицирует rolling workers: смешанные версии требуют политики drain старых workers или owner tuple-теста | not_checked | владелец: `packages/drive`/`core`, линия 1.x; BC-pass не заявляется |
| Схема сохранённых документов, wire-версии протокола | вручную / not_checked | версии растут только совместимо | not_checked | владелец: `packages/documents`/`collaboration`, линия 1.x |
| Env хаба/образа | исходники/конфиг `packages/collaboration/hub` и приватные docs; вручную / not_checked, Docker runtime отложен в P8.2 | имена не удаляются без alias | not_checked | владелец: `packages/collaboration`, линия 1.x |
| npm `@vaulter/vue` / `@vaulter/vue-collaboration` | `etc/index.api.md`, `etc/components.surface.txt` каждого пакета, публичные entrypoints и consumer type fixtures | удаление экспорта — BREAK; изменение деклараций, обязательных props или emits — REVIEW | `api:surface:check` сверяет декларации с кодом в обоих Vue jobs; trusted-координатор `npm-surface` сравнивает их с предшественником независимой release-line | только декларации; packed/runtime-квалификация — P8.2 |
| Root-only ключи `composer.json` (D8) | корневой `composer.json` | split-пакеты не наследуют root-only ключи; не обещание пакета | not_checked | вручную |

Именованные аргументы — часть API для типов `#[Api]`; нестабильные конструкторы — `#[Internal]` (D27, `tests/Repo/Arch/SurfaceRulesTest.php`). Атрибуты не гарантируют поведение.

### Политика deprecation (1.x)

Устаревшее живёт минимум один минорный цикл, предупреждает один раз за процесс (`E_USER_DEPRECATED` или лог, не в hot path), называет замену и версию удаления; конфиг-alias читает старое значение, doctor сообщает миграцию. Удаление — только в 2.0, кроме security-исключений с advisory. См. ADR-0037.


### BC gate: source, freeze and trusted tool identities (D18)

| Identity | Authority and purpose | Boundary |
|:--|:--|:--|
| S | Committed candidate source SHA; `--root` supplies bytes and static snapshots only | Candidate autoload, PHP, hooks, plugins and tests supply no approval authority |
| F | Freeze metadata commit, sole parent S; only the release manifest differs | F is not a replacement release source or a rebuild |
| T | Externally approved policy and complete tool revision, read-only after provisioning | Parser, checker, helpers, locks and ignored vendor/autoload inputs belong to T |
| A | Protected external exact approval bundle for S, policy, exceptions and tool closure | Identical surface hashes do not transfer approval to another S |

`contract-bc` participates in CI lane selection, `needs` and the final `CI gate` verdict.
A required failed, cancelled, missing or skipped job blocks the gate. The standalone
OpenAPI workflow calls the same externally provisioned host bootstrap. Both public
`openapi/vaulter-v1.yaml` (core / php-public) and private hub spec (collaboration /
php-collaboration) use their resolved release-line bases. Local referenced files and
JSON-pointer fragments must resolve on both sides, including shared schemas. Remote
refs, missing inputs and an unavailable oasdiff are `not_checked` / exit 2 in CI.
The transitional tested pin is `tufin/oasdiff:v1.33.0`, in the trusted wrapper's
`Contract/openapi-check.py`; P7.1 has not yet supplied a pins manifest.
Synthetic oasdiff records argv and exit propagation only; it proves no REST compatibility.

Owner host provisioning is required before this lane can pass: provision an externally
managed absolute Python and approved external copy of `Contract/trusted-host.py`, then
supply `BC_HOST_PYTHON`, `BC_HOST_BOOTSTRAP`, `BC_TOOL_ROOT`, `BC_TOOL_REV`,
`BC_SHA256SUMS`, `BC_RUNTIME_APPROVAL`, and `BC_RUNTIME_APPROVAL_SHA256` through the
protected control plane. Never obtain approval from candidate files. No such external
provisioning or branch-protection enforcement is claimed by these repository fixtures.
Missing provisioning blocks, rather than qualifying a release.

The bootstrap is invoked with isolated Python (`-I`) outside the candidate cwd. Its
external SHA256SUMS must enumerate exactly every regular file in T, including ignored
dependencies/autoload; only root `.git` metadata is excluded. Normalized relative paths,
unique entries, no symlinks, no missing/extra/changed files, and HEAD=T are mandatory.
The checksum inventory and runtime approval live outside T. The runtime JSON contains
`php` (absolute binary), `sha256`, exact `configuration` (`version`, `ini`, `scanned`,
`extensions`), `config_files` (absolute config paths and hashes), and exact `environment`
(including absolute `BC_PYTHON` for the wrapper). PHP 8.4 and matching configuration,
extensions, environment and bytes are required. PHP children use that approved binary
(`PHP_BINARY` in PHP fixtures); the host never invokes `scripts/php84`, which writes a
shim inside its checkout. Scratch files live outside T. Git metadata integrity, protected
mounts, the external bootstrap and runtime approval remain owner prerequisites.

Trusted policy `Contract/bc-trust-policy.json` требует четыре независимые release-line
и не содержит approvals:

| Обязательная линия | Семейство тегов | Зафиксированный major |
|:--|:--|:--|
| `php-public` | `v<version>` | 1 |
| `php-collaboration` | `collaboration-v<version>` | 1 |
| `npm-vue` | `@vaulter/vue@<version>` | 1 |
| `npm-vue-collaboration` | `@vaulter/vue-collaboration@<version>` | 0 |

Major npm берётся из независимой версии соответствующего пакета: сейчас 1.0.0 и 0.0.0.
Поле `api: 1` release manifest не означает версию 1.x приватного пакета. Новый major
требует явного изменения trusted policy. Семейства тегов и каналы заданы в
`release/packages.json`; npm-тег не выбирает PHP-базу или базу соседнего npm-пакета.
Host требует все четыре линии в policy и отчёте. `BC_SELECTED_LINES=all` выбирает
полный trusted-набор; урезанный `BC_REQUIRED_LINES` или selection, отсутствие линии,
skipped/deferred-покрытие и несогласованный process status блокируют результат.
Успешный OpenAPI не маскирует BREAK/REVIEW или tool error координатора. Плановые
required/selected outputs и реальные зависимости jobs входят в итоговый CI gate;
даже Vue-only изменение требует координатор всех четырёх линий.

Оба Vue jobs выполняют блокирующий `api:surface:check` без secrets и сохранённых
checkout credentials, с правами только contents:read. Эта проверка регенерирует
декларации и сверяет committed reports с исходниками; она не одобряет изменение отчёта.
Trusted-координатор отдельно сравнивает base/candidate reports адаптером `npm-surface`
и применяет точные разрешения. Публичный `api:check` по-прежнему проверяет generated
OpenAPI types и не заменяет ни одну из проверок деклараций.

Отсутствующий известный release — tooling error. При отсутствии предшественника
статус `initial_bootstrap` не является BC pass относительно самого себя; первый выпуск
может допустить только внешнее точное D18 approval. Отчёт разделяет изменения,
ошибки, выбранные базы, SHA policy/source, `not_checked` и pending qualification.
Структурные сигнатуры, identities/schema и npm declaration guards не квалифицируют
runtime-семантику, serial workers, реальные tgz/ZIP consumers, Docker, установку и
upgrade. Эти проверки остаются за владельцем в P8.2; в синтетических отчётах
actual runtime qualification всегда false.

Синтетические проверки подтверждают оркестрацию и границу доверия. Реальная квалификация REST, Docker, архивов, runtime и upgrade отложена на P8.2; внешняя настройка владельца не считается выполненной.
