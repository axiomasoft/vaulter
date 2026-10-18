---
title: Архитектура
description: Слоистая архитектура Vaulter, его доменная структура, контракты, к которым подключается хост, и ADR, которые всё это решают.
outline: deep
---

# Архитектура

Vaulter — это строго типизированный слоистый Laravel-пакет, созданный, чтобы **встраиваться** в
существующее приложение. Ведущий принцип — чистое разделение владения:

- **Всё, что должен предоставить хост, — это контракт**: авторизация, байтовая плоскость,
  транспорт совместного редактирования, форма share-токена, обработчики форматов документов.
  Каждый — это интерфейс, привязанный в контейнере и сменяемый через конфиг.
- **Всё, чем владеет Vaulter, — `final` и типизировано**: движок эффективных прав, Drive Actions,
  жизненный цикл CAS, реестр панелей. Это не контракты — это гарантии пакета.

В итоге один пакет ложится и в обычное Laravel-приложение, *и* в мультитенантный CoreX SaaS без
форка — см. [ADR-0003](/adr/0003-embeddability-data-model) и его преемников
[ADR-0009](/adr/0009-storage-panels) / [ADR-0011](/adr/0011-id-strategy).

::: tip С чего начать
Если вам нужно просто пользоваться Vaulter, читайте [Быстрый старт](/ru/getting-started/quick-start) и
[Конфигурацию](/ru/guides/configuration). Эта страница — ментальная модель за ними, полезна, когда
вы расширяете шов или отлаживаете слой.
:::

## Запрос от начала до конца

```
@vaulter/vue  ──HTTP/JSON──►  Controllers ─► FormRequests ─► DTOs (Data\*)
              ──WebSocket──►  CollaborationDriver (Hocuspocus)
       │
  AccessResolver (Vaulter-owned)  ◄── PermissionDriver (host, swappable)
       │                              + v_node_grants ACL
  Actions (final readonly · transactional · dispatch domain Events)
       │
  Repositories (read / store split)
       │
  CAS:  BlobStore ─► ContentStore (sha256) ─► StorageDriver (Flysystem disk)
       │
  Models (swappable via config) ─► Database (v_* tables, one id-strategy)
```

Типичная запись — скажем, `POST v1/drives/{drive}/files` — течёт сверху вниз:

1. Однодейственный **контроллер** находит `{drive}` (или `{node}`) по id через
   `HttpResourceResolver` с фильтром арендатора — привязки моделей к маршрутам нет — и вызывает
   операции от имени пользователя запроса (`Vaulter::drive($drive)->actingAs($user)`), а те
   авторизуют действие против [`AccessResolver`](#авторизация).
2. **FormRequest** валидирует, а **DTO** (`Vaulter\Data\*`) несёт типизированный payload.
3. **Action** (`final readonly`, например `IngestFileAction`) открывает транзакцию, вызывает
   **CAS** для staging + дедупликации байтов, пишет строки `v_nodes` / `v_files` через
   **store-репозитории** и диспатчит доменный **Event** после коммита.
4. Подписчики (`RecordsActivity`, `RecordsOutbox`) реагируют на событие, чтобы записать
   ленту активности и застейджить транзакционный outbox — Vaulter никогда не связывает Action с
   ними напрямую.

Эргономичная точка входа `Vaulter::drive($owner)` возвращает
[`DriveHandle`](#drive-хэндл) (`Vaulter\Drive\DriveHandle` из пакета
`axioma-studio/vaulter-drive`), который оборачивает ровно этот конвейер для программного
использования.

## Слои

### HTTP-край — Controllers, FormRequests, Resources

Тонкий край. Каждый пакет держит маршруты своего понятия (`packages/drive/routes/drive.php`,
`packages/documents/routes/documents.php`, `packages/webhooks/routes/webhooks.php`); его модуль монтирует их под
`config('vaulter.routes.prefix')` (по умолчанию `api/vaulter`) с middleware `vaulter.module:<id>`. Контроллеры авторизуют, передают DTO
Action-у и сериализуют результат через Resource, который встраивает вычисленный объект `abilities`,
чтобы Vue-клиент рисовал только разрешённые действия.

Параметры маршрута `{drive}` и `{node}` разрешает тенант-скоупленный `HttpResourceResolver` по **сменяемым**
моделям (`config('vaulter-drive.models.drive|node')`) — поэтому хост может указать в конфиге на подкласс, и
разрешение продолжит работать.

### Actions — `Vaulter\Drive\Actions\*` / `Vaulter\Documents\Actions\*` / `Vaulter\Webhooks\Actions\*`

Один use case на каждый, `final readonly`. Action открывает транзакцию, применяет бизнес-правила,
вызывает репозитории/CAS и диспатчит доменное событие **после коммита**. Набор Drive на запись
(`Vaulter\Drive\Actions\*`, требует `axioma-studio/vaulter-drive`):
`CreateFolderAction`, `IngestFileAction`, `MoveNodeAction`, `RenameNodeAction`,
`CopyNodeAction`, `TrashNodeAction`, `RestoreNodeAction`, `PurgeNodeAction`. Actions
документных узлов живут в `Vaulter\Documents\Actions\*` (требует
`axioma-studio/vaulter-documents`); Actions эндпоинтов вебхуков живут в `Vaulter\Webhooks\Actions\*`
(`axioma-studio/vaulter-webhooks`). В ядре Actions нет: оно только запускает операции.

::: info Размещение авторизации
Actions **не** выполняют никаких проверок доступа. Авторизация применяется один раз, на HTTP-границе
(`NodeController` + `AccessResolver`), до запуска Action. `DriveHandle` — тоже чистая поверхность
делегирования. Так решение о доступе остаётся ровно в одном месте.
:::

### Repositories — разделение read / store

Чтения (`NodeReadRepository`, `GrantReadRepository`, `DriveReadRepository`, …) строят запросы;
store-репозитории (`NodeStoreRepository`, `DriveStoreRepository`, …) выполняют записи и никогда не
открывают транзакции и не диспатчат события — этим владеет Action. Чтения-спуски по дереву используют
рекурсивные связи `staudenmeir/laravel-adjacency-list`, ограниченные по `drive_id`.

### Models — сменяемые, резолвятся через `Config`

Vaulter никогда не ссылается на конкретный класс модели напрямую в доменном коде; каждый пакет резолвит свои
модели через собственный типизированный читатель конфига (`Vaulter\Drive\Configuration\DriveConfig`,
`Vaulter\Media\Configuration\MediaConfig`, `Vaulter\Webhooks\Configuration\WebhooksConfig`) по
`config('vaulter-drive.models.*')`, `config('vaulter-media.models.*')`, `config('vaulter-webhooks.models.*')`. Хост наследует модель, чтобы добавить
поведение ULID, тенант-скоуп или аудит. Модели — единственная часть пакета, которая **не** `final`
(их должно быть можно расширять); всё остальное — `final`.

### Value-объекты, DTO, enum-ы

`Vaulter\Drive\Data\*` — это `final readonly` DTO, которые потребляют Actions. `Vaulter\Drive\ValueObjects\*`
держат доменные значения (`FileDescriptor`, `ContentCapabilities`, ACL-овые
`Subject`/`Decision`/`GrantSet`/`AccessScope`) — только kind-нейтральные; core не знает форму контента
ни одного обработчика (`TipTapContent` и соседи живут в `vaulter-documents`).
`Vaulter\Drive\Enums\*` и `Enums\*` других пакетов — это запечатанные словари (`Ability`, `NodeKind`, `BlobStatus`, …).

## Доменная структура

Владение следует графу Composer (`core <- media <- drive <- documents <- collaboration`; `webhooks`, `azgard` и
`filament` строятся поверх `drive`) и зафиксировано в [ADR-0030](/adr/0030-package-ownership-and-schema) и
[ADR-0031](/adr/0031-collaboration-package-and-durability).
**`axioma-studio/vaulter-core`** (namespace `Vaulter\`) — только ядро: в нём нет доменных таблиц, моделей и
сервисов модулей.

| Директория (core) | Ответственность |
|---|---|
| `Modules/` · `Kernel.php` | Модульная система: манифесты, компиляция и seal, capabilities, слоты, активация ([Модули](/ru/concepts/modules)). |
| `Operations/` | `OperationRunner`, `OperationContext` (актор, тенант, ключ идемпотентности) и конвейер middleware, через который идёт каждая запись ([Операции](/ru/concepts/operations)). |
| `Events/` | `DomainEvent`, `EventCatalog` (типы событий, которые вносят модули), транзакционный outbox и его relay (`Events/Outbox/`) ([Outbox](/ru/concepts/events-outbox)). |
| `Idempotency/` | Хранилище и middleware `Idempotency-Key` ([Идемпотентность](/ru/concepts/idempotency)). |
| `Http/` | HTTP-каркас: стеки middleware, рендер проблем (`Problems/`), контракт слота меток маршрутов. Маршрутов модулей нет. |
| `Configuration/` · `Doctor/` · `Console/` | Читатель и валидатор конфига ядра, каркас doctor и команды ядра (`vaulter:install`, `vaulter:modules`, `vaulter:module:uninstall`, `vaulter:doctor`, `vaulter:relay`, генераторы справочников). |
| `Schema/` | Только общие хелперы: стратегии id (`AutoIncrementIds`, `UlidIds`, `Uuid7Ids`), host-key колонки, `SchemaHelpers`, DDL четырёх таблиц ядра и `ModuleSchemas`, обходящий schema-классы скомпилированных модулей. |
| `Tenancy/` · `Observability/` · `Support/` | Контракт резолвера тенанта, телеметрия и рекордеры, помощники identity и запроса. |
| `Testing/` | Публичный кит `Vaulter\Testing`, на котором строится `TestCase` каждого пакета. |

| Пакет | Namespace | Владеет |
|---|---|---|
| `axioma-studio/vaulter-media` | `Vaulter\Media\` | `v_blobs` и `v_blob_references` (пины), контракты и движок CAS (`Cas\{BlobStore,Sha256ContentStore,FlysystemStorageDriver,BlobGarbageCollector,BlobReconciler,StagedTempObject}`, `Contracts\Cas\BlobPins`), `vaulter:blobs:gc` и `vaulter:blobs:reconcile`. |
| `axioma-studio/vaulter-drive` | `Vaulter\Drive\` | Точка входа `Vaulter\Drive\Vaulter` (фасад `Vaulter\Drive\Facades\Vaulter`, алиас `Vaulter`), `DriveHandle`, Actions и события, доступ (`AccessResolver`, драйверы прав), sharing, активность, журнал изменений, квоты, загрузки, состояние сканирования, профили, обработчики файлов, а также таблицы, маршруты, команды и doctor-проверки drive. |
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\` | Actions/события/модели документных узлов, реестр типов документов (`Types\*`), презентер документов, экспорт, контракты коллаборации (`CollaborationDriver`, `SupportsCollaboration`) и нейтральный контракт состояния `State\CollaborativeState` с pending-маркером, формы контента (`Content\OpaqueContent`, `Content\TipTap\*`). |
| `axioma-studio/vaulter-collaboration` | `Vaulter\Collaboration\` | Совместное редактирование в реальном времени: эндпоинт сессии и room-токен, протокол хаба 2 (`authenticate`, `load`, `append`, `renew`, `recheck`, lease и checkpoint компактации), журнал обновлений, квитанции и версии прав (`v_collaboration_*`), команды drain и prune, control-consumer. Node-хаб `@vaulter/collaboration-hub` живёт в `packages/collaboration/hub`. Opt-in. |
| `axioma-studio/vaulter-webhooks` | `Vaulter\Webhooks\` | Исходящие вебхуки: эндпоинты, `WebhookFanoutConsumer` (outbox-потребитель `webhooks.fanout`), `WebhookSigner`, URL-guard, повторы, админский HTTP и команды `vaulter:webhooks:*`. Opt-in. |
| `axioma-studio/vaulter-filament` | `Vaulter\Filament\` | Admin-плагин Filament 5 поверх авторизованных Operations drive. |
| `axioma-studio/vaulter-azgard` | `Vaulter\Azgard\` | Драйвер прав AzGuard, контракт guard, карта прав, реальный адаптер и тестовый fake. |
| `@vaulter/vue-collaboration` | — | Браузерная часть коллаборации (npm): конверты обновлений, очередь повторов, статус сохранения; подключается к `@vaulter/vue` через его адаптер `collaboration`. Единственный пакет с `yjs` и `@hocuspocus/provider`. |
| `@vaulter/vue` | — | UI Drive + composables (npm). `src/api` не импортирует Vue, редактор и realtime-пакеты и ничего из `src/` вне `src/api` (arch-тест `packages/vue/tests/arch/api-no-vue.test.ts`). |

Метапакет `axioma-studio/vaulter` ставит core, media, drive и documents; webhooks, collaboration, azgard и filament
ставятся отдельно (см. [Установку](/ru/getting-started/installation)). Каждый пакет поставляет свои миграции
(не публикуются), свой файл конфига (`vaulter`, `vaulter-media`, `vaulter-drive`, `vaulter-documents`,
`vaulter-webhooks`, `vaulter-collaboration`, `vaulter-azgard`, `vaulter-filament`) и свой тестовый кит.

## Drive-хэндл

`Vaulter::drive($owner, profile: '…')` — основная точка входа для интеграции. `Vaulter` — фасад
`Vaulter\Drive\Vaulter` (алиас `Vaulter` объявляет пакет drive); он возвращает `DriveHandle` — пакет должен
быть установлен, чтобы этот хэндл был рабочим:

```php
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;

// Get-or-create the drive mounted on $project in the 'default' profile,
// returning an ergonomic DriveHandle.
$drive = Vaulter::drive($project)->actingAs($user);

$folder = $drive->folders()->create('Contracts');
$node   = $drive->files()->upload(
    UploadSource::fromUploadedFile($request->file('doc')),
    new UploadOptions(parent: $folder),
);
```

`Vaulter::drive()` находит-или-создаёт строку `v_drives` через `DriveProvisioner` (полиморфная тройка
`(owner_type, owner_id, profile)` — ключ идемпотентности, защищённый уникальным индексом), автоматически
провижинит корневой узел `kind=folder` и возвращает `DriveHandle`, чьи методы каждый делегируют одной
Operation. Хэндл не добавляет бизнес-логики — только предрезолвленный drive и нормализацию источника загрузки.

::: details Vaulter
`drive()`, `findDrive()`, `findDriveById()`, `drives()`, `profiles()`, `handlers()`, `permissions()`.
[Фасад](/ru/reference/php-api).
:::

## Storage Panels — ось встраиваемости

**Storage Profile** — это именованный конфиг-конверт, привязанный к *любой* сущности хоста, живущий
поверх **одной общей схемы `v_*` без собственных таблиц** (ноль миграций). Это ось
изоляции/конфигурации, отдельная от *типа хранилища* (пресет политики `private | team | public`).

Панель строится флюентно и регистрируется только по FQCN (безопасно для config-cache — никаких
замыканий):

```php
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileProvider;

final class ProjectDriveProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('project_drive')
            ->host(\App\Models\Project::class)
            ->storageType('team');
    }
}
```

Зарегистрируйте провайдер в `config('vaulter-drive.profiles.providers')`. `ProfileRegistry` разрешает
профили при загрузке приложения. Создайте провайдер командой
`php artisan vaulter:make:profile <name>`. Все профили используют общую схему;
собственные таблицы, карты моделей, pipelines и resource-хуки удалены.
См. [Профили Drive](/ru/concepts/drives-and-profiles).

## Модель данных

Всё живёт в префиксной схеме `v_*` (`config('vaulter.database.table_prefix')`, по умолчанию `v_`), с одной
стратегией идентификаторов на инсталляцию (`config('vaulter.ids.strategy')`, по умолчанию `ulid`). Каждая таблица
принадлежит ровно одному пакету, который создаёт её из своих `database/migrations`
([ADR-0030](/adr/0030-package-ownership-and-schema)).

| Таблица | Пакет | Что держит |
|---|---|---|
| `v_outbox` / `v_outbox_consumers` / `v_outbox_deliveries` | core | Транзакционный outbox всех событий и состояние доставки его потребителей ([Outbox](/ru/concepts/events-outbox)). |
| `v_idempotency_keys` | core | Квитанции записей с `Idempotency-Key` ([Идемпотентность](/ru/concepts/idempotency)). |
| `v_blobs` | media | CAS: по одной строке на `(scope, sha256)` с жизненным циклом `BlobStatus` ([ADR-0006](/adr/0006-content-addressed-blobs-gc)). |
| `v_blob_references` | media | **Пины**: кто держит блоб живым, как `(blob, owner_type, reference_key)`. Сборка мусора читает только эту таблицу. |
| `v_drives` | drive | По одному drive на `(owner_type, owner_id, profile)`; несёт id корневого узла. |
| `v_nodes` | drive | **Единое дерево** — каждая папка, файл, документ, ярлык как одна строка с `parent_id` + дискриминатором `kind` ([ADR-0005](/adr/0005-unified-node-tree)). |
| `v_files` / `v_file_revisions` / `v_file_revision_blobs` | drive | Бинарные боковые таблицы: голова файла + цепочка ревизий + связи с блобами (каждая связь ещё и пинит свой блоб). |
| `v_scan_*` | drive | Политика сканирования содержимого, обязательства, запросы, свидетельства и прогоны backfill. |
| `v_node_grants` / `v_node_user_states` | drive | Пер-узловой ACL с наследованием + вето-запретом и пер-пользовательское состояние узла. |
| `v_node_shares` | drive | Bearer-ссылки (share) — хранение только хеша токена ([ADR-0013](/adr/0013-two-sharing-channels)). |
| `v_upload_sessions` / `v_upload_parts` | drive | Сессии резюмируемых загрузок. |
| `v_activity` | drive | Append-only лента активности. |
| `v_drive_change_heads` / `v_drive_changes` | drive | Журнал изменений drive. |
| `v_documents` / `v_document_revisions` / `v_node_comments` | documents | Агрегат документа узла `kind=document` (рабочее состояние, голова, валидаторы), его неизменяемые чекпоинты и комментарии. |
| `v_webhook_endpoints` / `v_webhook_deliveries` | webhooks | Эндпоинты вебхуков и их доставки. |

Дерево — это **список смежности** (adjacency list): перемещение/переименование — это единственный
`UPDATE` `parent_id` / `name` (без переписывания путей). Спуск использует рекурсивные CTE,
ограниченные по `drive_id`; рекурсивная *агрегация* потомков (`whereHas` /
`withCount('descendants')`) **запрещена на путях запросов** (фатально на MariaDB) и обеспечивается
arch-тестом — см. [Устранение неполадок](/ru/guides/troubleshooting#mariadb-cte).

## Авторизация

Две полосы, оцениваемые принадлежащим Vaulter `AccessResolver`
([ADR-0007](/adr/0007-pluggable-authorization)):

- **`PermissionDriver`** — подключаемая, пер-панельная ролевая полоса хоста. Она никогда не видит
  `Node` и никогда не оперирует magic-string-действиями; она отвечает на
  `resolve(Subject, Authorizable, AccessScope)`, `override(...)` (супер-админ / `Gate::before`),
  `subjectsFor(Authorizable)` (актор → пользователи + роли + команды) и
  `canCreateInWorkspace(...)`. Поставляемые полосы: `database` (по умолчанию, автономная),
  `gate`, `spatie`, `azgard` и не-прод `null`. Меняется через
  `config('vaulter-drive.access.permission_driver')`.
- **`AccessResolver`** — принадлежит Vaulter, `final`, **не** сменяем. Прогоняет канонический
  алгоритм поверх `v_node_grants` в комбинации с драйвером.

Алгоритм (вето-запрет, явное бьёт унаследованное):

1. **Override хоста** (супер-админ) абсолютен, в обе стороны.
2. Идём по предкам **ближайшие-первыми**, ограниченно до корня хранилища, собирая прямые +
   `scope=subtree` гранты для subject-set актора; **останавливаемся** на первом предке с
   `inherit_grants = false`.
3. `aclAllow = max(allow bits)`; `deniedBits = OR(deny bits)`; **более близкий** явный allow
   пробивает **более далёкий** унаследованный deny (deny на том же узле всегда побеждает).
4. `granted = max(aclAllow, roleAllow)`, где `roleAllow` приходит от драйвера.
5. **Финальное вето**: если `deniedBits` покрывает запрошенное право → DENY, независимо от роли.

Права (`Vaulter\Drive\Enums\Access\Ability`) отображаются на CRUDS-биты: `View/Download/Comment → READ`,
`Edit → UPDATE`, `Create/Copy → CREATE`, `Move → UPDATE+CREATE`, `Trash/Restore/Delete → DELETE`,
`Share → SHARE`, `Manage → ALL`.

::: warning Нет драйвера = громкий отказ в продакшене
Открытый драйвер `null` можно выбрать **только вне продакшена**; `PermissionDriverManager`
кидает `MissingPermissionDriverException`, если продакшен-приложение его резолвит. Привяжите настоящий
драйвер до деплоя — см. [Устранение неполадок](/ru/guides/troubleshooting#no-permission-driver).
:::

ACL по идентичности и канал **bearer-ссылок** оцениваются как **отдельные проходы, никогда не
объединяемые** ([ADR-0013](/adr/0013-two-sharing-channels)) — ссылка не может эскалироваться через
гранты просматривающего, а грант по идентичности не может утечь через ссылку.

## Контент-адресуемое хранилище (CAS)

Бинарная плоскость — это контент-адресуемое хранилище, а не `spatie/laravel-medialibrary`
([ADR-0008](/adr/0008-media-library-decision)). Три сменяемых сервиса
(`config('vaulter-media.*')`):

- **`ContentStore`** (`Sha256ContentStore`) — стейджит байты в seekable-временный файл и вычисляет
  контент-адрес `sha256` из застейдженного файла (никогда из живого стрима или присланного клиентом
  хеша).
- **`StorageDriver`** (`FlysystemStorageDriver`) — байтовая плоскость поверх Flysystem-диска, с
  условным `put` (`If-None-Match: *` на S3) и `temporaryUrl`.
- **`BlobStore`** — гонкоустойчивый оркестратор жизненного цикла. Живость — это *существование
  пина* в `v_blob_references` (собственная таблица media, пишется через контракт `BlobPins` пакетом, который
  держит байты живыми — drive пинит в той же транзакции, что и свою связь с ревизией); `ref_count` —
  перестраиваемый кеш. Сборка мусора двухфазная и
  lock-first (`PENDING → COMMITTED → TOMBSTONED → DELETED`) с воскрешением затомбстоненного блоба,
  который конкурентная загрузка снова зареференсила.

Физический ключ **квалифицирован по scope** — `<scope>/ab/cd/<sha256>`, где `<scope>` следует
`config('vaulter-media.dedup.scope')` (`tenant` по умолчанию), поэтому сборка мусора одного scope никогда
не может удалить байты другого scope ([ADR-0012](/adr/0012-dedup-scope)). См.
[рецепт сборки мусора блобов](/ru/guides/recipes#blob-gc) и
[рецепт кастомного storage-драйвера](/ru/guides/recipes#s3-disk).

## Совместное редактирование в реальном времени

Источник истины — Yjs-обновления; JSON TipTap — проекция чекпоинта. Коллаборация — opt-in пакет `vaulter-collaboration`
([ADR-0031](/adr/0031-collaboration-package-and-durability); выбор транспорта —
[ADR-0001](/adr/0001-collaboration-transport)); documents хранит агрегат и контракт `CollaborationDriver`:

- **`hocuspocus`** — Node-хаб `@vaulter/collaboration-hub`. Клиент подключается с подписанным room JWT; хаб обращается в
  эндпоинты протокола 2 (`v1/collaboration/hub/{authenticate,load,append,renew,recheck}` и `lease`/`checkpoint`
  компактации). Сохранение — это `append`, возвращающий зафиксированную квитанцию; хаб сворачивает журнал в чекпоинт.
  Ни drive, ни documents от него не зависят. См. [Совместное редактирование](/ru/guides/collaboration).

## Точки расширения

Каждый шов, к которому подключается хост, и где он задокументирован:

| Контракт | Привязка через | Задокументировано в |
|---|---|---|
| `Vaulter\Drive\Contracts\Access\PermissionDriver` | `config('vaulter-drive.access.permission_driver')` | [Рецепты — кастомный драйвер прав](/ru/guides/recipes#permission-driver) |
| `Vaulter\Azgard\Contracts\AzgardGuard` | ключ конфига `vaulter-azgard.guard` (по умолчанию — адаптер моста) | [Устранение неполадок — морфы Azgard](/ru/guides/troubleshooting#azgard-morphs) |
| `Vaulter\Media\Contracts\Cas\StorageDriver` / `ContentStore` / `BlobStore` | `config('vaulter-media.*')` | [Рецепты — переход на S3](/ru/guides/recipes#s3-disk) |
| `Vaulter\Documents\Contracts\CollaborationDriver` | `config('vaulter-collaboration.driver')` | [ADR-0001](/adr/0001-collaboration-transport), [ADR-0031](/adr/0031-collaboration-package-and-durability) |
| `Vaulter\Drive\Contracts\Handlers\FileHandler` | `config('vaulter-drive.handlers')` / `Vaulter::handlers()->register()` | [Рецепты — кастомный обработчик формата](/ru/guides/recipes#format-handler) |
| `Vaulter\Documents\Contracts\DocumentExporter` | `config('vaulter-documents.exporters')` / `Documents::exporters()->register()` | [ADR-0004](/adr/0004-content-format) |
| `Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator` | `config('vaulter-drive.sharing.token_generator')` | [ADR-0013](/adr/0013-two-sharing-channels) |
| `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler` | тег контейнера | [ADR-0006](/adr/0006-content-addressed-blobs-gc) |
| `Vaulter\Contracts\IdStrategy` | `config('vaulter.ids.strategies')` | [ADR-0011](/adr/0011-id-strategy) |
| `config('vaulter-drive.models.*')`, `config('vaulter-media.models.*')`, `config('vaulter-webhooks.models.*')` | конфиг | [ADR-0003](/adr/0003-embeddability-data-model) |
| Доменные `Events` | подписчик событий | подцепить аудит/уведомления без зависимости Vaulter от них |

## Реестр решений

Обоснование каждого выбора выше живёт в [ADR](/adr/). Полный набор:

| ADR | Что решает | Статус |
|---|---|---|
| [0001](/adr/0001-collaboration-transport) | Транспорт совместного редактирования — `CollaborationDriver`, Hocuspocus и room-JWT; транспорт Yjs через Reverb удалён. | accepted |
| [0002](/adr/0002-authorization-delegation) | Авторизация делегирована хосту (`PermissionResolver`). | refined by 0007 |
| [0003](/adr/0003-embeddability-data-model) | Встраиваемость через сменяемые модели + двойной тип ключа. | superseded by 0009 / 0011 |
| [0004](/adr/0004-content-format) | Контент в TipTap JSON + append-only ограниченная история версий. | accepted |
| [0005](/adr/0005-unified-node-tree) | Одно дерево-список-смежности (`v_nodes`) для папок/файлов/документов/ярлыков. | accepted |
| [0006](/adr/0006-content-addressed-blobs-gc) | Контент-адресуемые блобы + двухфазная гонкоустойчивая сборка мусора. | accepted |
| [0007](/adr/0007-pluggable-authorization) | `PermissionDriver` (подключаемый) + `AccessResolver` (собственный), вето-запрет. | accepted |
| [0008](/adr/0008-media-library-decision) | Собственный CAS вместо `spatie/laravel-medialibrary`. | accepted |
| [0009](/adr/0009-storage-panels) | Storage Panels — config-first, конверты с нулём миграций. | accepted |
| [0010](/adr/0010-php-npm-monorepo-release) | Один репозиторий, два пакетных менеджера, два канала релиза. | accepted |
| [0011](/adr/0011-id-strategy) | `bigint | ulid | uuid7` за швом `IdStrategy` (ULID по умолчанию). | accepted |
| [0012](/adr/0012-dedup-scope) | Пер-тенантная дедупликация по умолчанию + квалифицированные по scope ключи хранения. | accepted |
| [0013](/adr/0013-two-sharing-channels) | ACL по идентичности и bearer-ссылки оцениваются как отдельные проходы. | accepted |
| [0027](/adr/0027-kernel-boundaries-and-modules) | Что такое ядро; модульная система (манифест, активация, capabilities, слоты). | accepted |
| [0028](/adr/0028-extension-slots-and-operation-pipeline) | Слоты расширения и конвейер операций. | accepted |
| [0029](/adr/0029-idempotency) | Квитанции `Idempotency-Key`, записываемые в транзакции мутации. | accepted |
| [0030](/adr/0030-package-ownership-and-schema) | Один владелец на таблицу, модель и корень конфига; пины; миграции и тестовые киты по пакетам. | accepted |
| [0031](/adr/0031-collaboration-package-and-durability) | Пакет коллаборации; квитанции протокола 2, режимы buffered/durable, компактация только на хабе, состояние pending, граница ревокации, drain. | accepted |

Цепочки замещения, которые стоит держать в голове: **0003 → 0009 / 0011** (механика встраиваемости),
**0002 → 0007** (делегирование авторизации), **0019 → 0027 → 0030** (кто чем владеет) и **0001 → 0031** (сохранность и размещение коллаборации).

## Дальше

- [Рецепты](/ru/guides/recipes) — проработанные, готовые к копипасту интеграции этих швов.
- [Устранение неполадок](/ru/guides/troubleshooting) — режимы отказа, которые создаёт эта
  архитектура, и как их избежать.
- [Конфигурация](/ru/guides/configuration) — каждый config-ключ, по секциям.
