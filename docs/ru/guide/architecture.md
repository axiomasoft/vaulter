---
title: Архитектура
description: Слоистая архитектура Vaulter, его доменная структура, контракты, к которым подключается хост, и 13 ADR, которые всё это решают.
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
Если вам нужно просто пользоваться Vaulter, читайте [Быстрый старт](/ru/guide/quick-start) и
[Конфигурацию](/ru/guide/configuration). Эта страница — ментальная модель за ними, полезна, когда
вы расширяете шов или отлаживаете слой.
:::

## Запрос от начала до конца

```
@vaulter/vue  ──HTTP/JSON──►  Controllers ─► FormRequests ─► DTOs (Data\*)
              ──WebSocket──►  CollaborationDriver (Reverb | Hocuspocus)
       │
  AccessResolver (Vaulter-owned)  ◄── HostRoleDriver (host, swappable)
       │                              + v_node_grants ACL
  Actions (final readonly · transactional · dispatch domain Events)
       │
  Repositories (read / store split)
       │
  CAS:  BlobStore ─► ContentStore (sha256) ─► StorageDriver (Flysystem disk)
       │
  Models (swappable via config) ─► Database (v_* tables, one id-strategy)
```

Типичная запись — скажем, `POST storages/{storage}/uploads` — течёт сверху вниз:

1. **Контроллер** через route-model-binding связывает `{storage}` (или `{node}`) через сменяемую
   модель, затем авторизует действие против [`AccessResolver`](#authorization).
2. **FormRequest** валидирует, а **DTO** (`Vaulter\Data\*`) несёт типизированный payload.
3. **Action** (`final readonly`, например `UploadFileAction`) открывает транзакцию, вызывает
   **CAS** для staging + дедупликации байтов, пишет строки `v_nodes` / `v_files` через
   **store-репозитории** и диспатчит доменный **Event** после коммита.
4. Подписчики (`RecordsActivity`, `RecordsOutbox`) реагируют на событие, чтобы записать
   ленту активности и застейджить транзакционный outbox — Vaulter никогда не связывает Action с
   ними напрямую.

Эргономичная точка входа `Vaulter::for($entity)` возвращает
[`DriveContext`](#the-drive-handle) (`Vaulter\Drive\DriveContext` из пакета
`axioma-studio/vaulter-drive`), который оборачивает ровно этот конвейер для программного
использования.

## Слои

### HTTP-край — Controllers, FormRequests, Resources

Тонкий край. Маршруты живут в `packages/core/routes/api.php`, монтируются под
`config('vaulter.route_prefix')` (по умолчанию `api/vaulter`). Контроллеры авторизуют, передают DTO
Action-у и сериализуют результат через Resource, который встраивает вычисленный объект `abilities`,
чтобы Vue-клиент рисовал только разрешённые действия.

Параметры маршрута `{storage}` и `{node}` связываются со **сменяемыми** моделями
(`config('vaulter.models.storage|node')`) через `Route::bind`, зарегистрированный в сервис-провайдере,
— поэтому хост может указать в конфиге на подкласс, и связывание продолжит работать.

### Actions — `Vaulter\Drive\Actions\*` / `Vaulter\Documents\Actions\*` / `Vaulter\Actions\*`

Один use case на каждый, `final readonly`. Action открывает транзакцию, применяет бизнес-правила,
вызывает репозитории/CAS и диспатчит доменное событие **после коммита**. Набор Drive на запись
(`Vaulter\Drive\Actions\*`, требует `axioma-studio/vaulter-drive`):
`CreateFolderAction`, `UploadFileAction`, `MoveNodeAction`, `RenameNodeAction`,
`CopyNodeAction`, `TrashNodeAction`, `RestoreNodeAction`, `ForceDeleteNodeAction`. Actions
документных узлов живут в `Vaulter\Documents\Actions\*` (требует
`axioma-studio/vaulter-documents`); сам core оставляет только
`Vaulter\Actions\{Access,Sharing,Webhooks}\*`.

::: info Размещение авторизации
Actions **не** выполняют никаких проверок доступа. Авторизация применяется один раз, на HTTP-границе
(`NodeController` + `AccessResolver`), до запуска Action. `DriveContext` — тоже чистая поверхность
делегирования. Так решение о доступе остаётся ровно в одном месте.
:::

### Repositories — разделение read / store

Чтения (`NodeReadRepository`, `GrantReadRepository`, `StorageReadRepository`, …) строят запросы;
store-репозитории (`NodeStoreRepository`, `StorageStoreRepository`, …) выполняют записи и никогда не
открывают транзакции и не диспатчат события — этим владеет Action. Чтения-спуски по дереву используют
рекурсивные связи `staudenmeir/laravel-adjacency-list`, ограниченные по `storage_id`.

### Models — сменяемые, резолвятся через `Config`

Vaulter никогда не ссылается на конкретный класс модели напрямую в доменном коде; он резолвит через
`Vaulter\Support\Config` по `config('vaulter.models.*')`. Хост наследует модель, чтобы добавить
поведение ULID, тенант-скоуп или аудит. Модели — единственная часть пакета, которая **не** `final`
(их должно быть можно расширять); всё остальное — `final`.

### Value-объекты, DTO, enum-ы

`Vaulter\Data\*` — это `final readonly` DTO, которые потребляют Actions. `Vaulter\ValueObjects\*`
держат доменные значения (`FileDescriptor`, `HandlerCapabilities`, ACL-овые
`Subject`/`Decision`/`GrantSet`/`AclScope`) — только kind-нейтральные; core не знает форму контента
ни одного обработчика (`TipTapContent` и соседи живут в `vaulter-documents`, VLT-CANON P0.3).
`Vaulter\Enums\*` — это запечатанные словари (`Ability`, `NodeKind`, `BlobStatus`, …).

## Доменная структура

После извлечения пакетов P2 #5 `axioma-studio/vaulter-core` (namespace `Vaulter\`) содержит
только сквозные швы — авторизацию, value-объекты/контракты CAS, sharing, панели, webhooks,
активность — под `packages/core/src`; Drive и Documents теперь поставляются отдельными
пакетами:

| Директория (core) | Ответственность |
|---|---|
| `Access/` | `AccessResolver` (движок эффективных прав), `HostRoleDriverManager`, `HostUserResolver`. |
| `Drivers/Permission/` | Поставляемые полосы `HostRoleDriver`: `database`, `gate`, `spatie`, `azgard`. |
| `Cas/` | `BlobLock` — единственный CAS-класс, оставшийся в core; сам движок хранилища переехал в `vaulter-media`. |
| `Panels/` | Билдер `Panel`, `StoragePanelProvider`, `PanelRegistry`, `PanelTablesBuilder`, `PanelMigrationGenerator`. |
| `Schema/` | Классы стратегий идентификаторов (`AutoIncrementIds`/`UlidIds`/`Uuid7Ids`) + `IdStrategyResolver`, колонки `PanelTablesBuilder`. |
| `Sharing/` | Канал bearer-ссылок: `ShareResolver`, `ShareContext`, `ShareResult`. |
| `Webhooks/` | Слой надёжности: `OutboxRecorder`, `OutboxRelay`, `WebhookSigner`. |
| `Activity/` | Append-only лента активности (`ActivityRecorder`, `RecordsActivity`). |
| `Actions/` · `Data/` · `Events/` | Только Access, Sharing и Webhooks — Actions/Events Drive и Documents теперь живут в своих пакетах (см. ниже). |
| `ValueObjects/Handlers/` | Kind-нейтральные value-объекты шва обработчиков, от которых зависят и Drive, и Documents: `FileDescriptor`, `HandlerCapabilities`, `HandlerUi`, `HandlerDescriptor`. |
| `Contracts/` | Каждый шов, обращённый к хосту, включая `Contracts/Drive/*` и `Contracts/Handlers/*` (интерфейсы, которые реализуют вынесенные пакеты). |
| `Models/` · `Repositories/` | Access, Activity, Cas, Drive, Sharing, Webhooks — общие/read-часть; свои write-модели поставляют и Drive, и Documents. |
| `Testing/` | Заглушки, поставляемые с пакетом: `NullRoleDriver`, `FakeAzgardGuard`. |

| Пакет | Namespace | Добавляет |
|---|---|---|
| `axioma-studio/vaulter-drive` | `Vaulter\Drive\` | `DriveContext`, `StorageManager`, Drive Actions (`CreateFolderAction`, `UploadFileAction`, `MoveNodeAction`, …) и их события. |
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\` | Actions/Events/модели документных узлов, `DocumentHandlerRegistry`, экспорт узлов, сервис сессий совместного редактирования узлов, формы TipTap-контента (`ValueObjects\{TipTapContent,OpaqueDocumentContent,LinkSchemeValidator}`, `Exceptions\InvalidTipTapContentException`). |
| `axioma-studio/vaulter-media` | `Vaulter\Media\` | Движок CAS: `Cas\{BlobStore,Sha256ContentStore,FlysystemStorageDriver,BlobGarbageCollector,BlobReconciler,StagedTempObject}`. |
| `axioma-studio/vaulter-filament` | `Vaulter\Filament\` | Админ-плагин для Filament 5. |
| `@vaulter/vue` | — | Drive UI + composable-функции (npm). |

Все они — соседние пакеты, строящиеся поверх `vaulter-core`; для функций Drive и
редактирования документов нужно установить соответствующий пакет (см.
[Установку](/ru/guide/installation)).

## Drive-хэндл

`Vaulter::for($entity, panel: '…')` — основная точка входа для интеграции. Это тонкий фасад над
`VaulterManager`, возвращающий `DriveContext` из `axioma-studio/vaulter-drive` (пакет должен
быть установлен, чтобы этот хэндл был рабочим):

```php
use Vaulter\Facades\Vaulter;

// Get-or-create the storage mounted on $project in the 'default' panel,
// returning an ergonomic DriveContext.
$drive = Vaulter::for($project);

$folder = $drive->createFolder('Contracts');
$node   = $drive->upload($request->file('doc'), into: $folder);
$drive->rename($node, 'signed.pdf');
$children = $drive->list($folder); // Collection<Node>
```

`VaulterManager::for()` находит-или-создаёт строку `v_storages` через `StorageManager`
(полиморфная тройка `(owner_type, owner_id, panel)` — ключ идемпотентности, защищённый частичным
уникальным индексом), автоматически провижинит корневой узел `kind=folder` и возвращает
`DriveContext`, чьи методы каждый делегируют одному Action или read-репозиторию. Хэндл не добавляет
бизнес-логики — только предрезолвленное хранилище и нормализацию источника загрузки (PSR-стрим,
`SplFileInfo` / `UploadedFile`, открытый ресурс или сырую строку — всё нормализуется к seekable-стриму).

::: details VaulterManager — полная поверхность фасада
`for()`, `forStorage()`, `panel()`, `panels()`, `registerPanel()`,
`resolvePermissionsWith()`, `permissionResolver()`, `registerDocumentType()`,
`documentTypes()`, `registerHandler()`, `handlers()`, `registerExporter()`,
`exporter()`, `exporterFormats()`, `driver()`. См.
[Рецепты](/ru/guide/recipes) по точкам расширения (`registerHandler` / `registerExporter` /
`resolvePermissionsWith`).
:::

## Storage Panels — ось встраиваемости

**Storage Panel** — это именованный конфиг-конверт, привязанный к *любой* сущности хоста, живущий
поверх **одной общей схемы `v_*` без собственных таблиц по умолчанию** (ноль миграций). Это ось
изоляции/конфигурации, отдельная от *типа хранилища* (пресет политики `private | team | public`).

Панель строится флюентно и регистрируется только по FQCN (безопасно для config-cache — никаких
замыканий):

```php
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class ProjectDriveStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project_drive')
            ->host(\App\Models\Project::class)
            ->storageType('team')
            ->feature('document_versions', true);
    }
}
```

Зарегистрируйте её под `config('vaulter.panels')`. Незаданные значения откатываются к глобальному
конфигу (**override → prefix → global**), поэтому панель объявляет только то, что переопределяет.
`PanelRegistry` — синглтон контейнера, который **валидирует каждую панель на бутстрапе** (H.1):
упомянутые FQCN моделей/pipe/resource должны существовать и удовлетворять своему контракту, а
панельная стратегия идентификаторов отвергается на общей схеме — сломанная панель падает громко на
бутстрапе, а не портит запрос. Заскаффолдить одну можно через `php artisan vaulter:make-panel <name>`.

Режим **собственных таблиц** (объявить `->tablePrefix()` / `->tables()`) — редкий продвинутый путь
для схемо-расходящегося домена; `vaulter:panel:migrations <panel>` тогда генерирует панельные
таблицы через то же определение `PanelTablesBuilder`, поэтому схема никогда не расходится. См.
[ADR-0009](/adr/0009-storage-panels).

## Модель данных

Всё живёт в префиксной схеме `v_*` (`config('vaulter.table_prefix')`, по умолчанию `v_`), с одной
стратегией идентификаторов на инсталляцию (`config('vaulter.ids.default')`, по умолчанию `ulid`).

| Таблица | Что держит |
|---|---|
| `v_storages` | По одному хранилищу на `(owner_type, owner_id, panel)`; несёт диск + id корневого узла. |
| `v_nodes` | **Единое дерево** — каждая папка, файл, документ, ярлык как одна строка с `parent_id` + дискриминатором `kind` ([ADR-0005](/adr/0005-unified-node-tree)). |
| `v_files` / `v_file_versions` / `v_file_version_blobs` | Бинарные боковые таблицы: голова файла + цепочка его версий + рёбра к блобам. |
| `v_blobs` | CAS: по одной строке на `(scope, sha256)` с жизненным циклом `BlobStatus` ([ADR-0006](/adr/0006-content-addressed-blobs-gc)). |
| `v_node_grants` | Пер-узловой ACL с наследованием + вето-запретом. |
| `v_node_shares` | Bearer-ссылки (share) — хранение только хеша токена ([ADR-0013](/adr/0013-two-sharing-channels)). |
| `v_node_document_versions` | Documents: история версий документа, привязанного к узлу (за фичей `document_versions`). |
| `v_upload_sessions` / `v_upload_parts` | Сессии резюмируемых загрузок. |
| `v_activity` | Append-only лента активности. |
| `v_outbox` / `v_webhook_endpoints` / `v_webhook_deliveries` | Слой надёжности транзакционного outbox + вебхуков. |

Дерево — это **список смежности** (adjacency list): перемещение/переименование — это единственный
`UPDATE` `parent_id` / `name` (без переписывания путей). Спуск использует рекурсивные CTE,
ограниченные по `storage_id`; рекурсивная *агрегация* потомков (`whereHas` /
`withCount('descendants')`) **запрещена на путях запросов** (фатально на MariaDB) и обеспечивается
arch-тестом — см. [Устранение неполадок](/ru/guide/troubleshooting#mariadb-cte).

## Авторизация

Две полосы, оцениваемые принадлежащим Vaulter `AccessResolver`
([ADR-0007](/adr/0007-pluggable-authorization)):

- **`HostRoleDriver`** — подключаемая, пер-панельная ролевая полоса хоста. Она никогда не видит
  `Node` и никогда не оперирует magic-string-действиями; она отвечает на
  `resolve(Subject, Authorizable, AclScope)`, `override(...)` (супер-админ / `Gate::before`),
  `subjectsFor(Authorizable)` (актор → пользователи + роли + команды) и
  `canCreateInWorkspace(...)`. Поставляемые полосы: `database` (по умолчанию, автономная),
  `gate`, `spatie`, `azgard` и не-прод `null`. Меняется через
  `config('vaulter.drivers.permission')`.
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

Права (`Vaulter\Enums\Access\Ability`) отображаются на CRUDS-биты: `View/Download/Comment → READ`,
`Edit → UPDATE`, `Create/Copy → CREATE`, `Move → UPDATE+CREATE`, `Trash/Restore/Delete → DELETE`,
`Share → SHARE`, `Manage → ALL`.

::: warning Нет драйвера = громкий отказ в продакшене
Открытый драйвер `null` можно выбрать **только вне продакшена**; `HostRoleDriverManager`
кидает `MissingHostRoleDriverException`, если продакшен-приложение его резолвит. Привяжите настоящий
драйвер до деплоя — см. [Устранение неполадок](/ru/guide/troubleshooting#no-permission-driver).
:::

ACL по идентичности и канал **bearer-ссылок** оцениваются как **отдельные проходы, никогда не
объединяемые** ([ADR-0013](/adr/0013-two-sharing-channels)) — ссылка не может эскалироваться через
гранты просматривающего, а грант по идентичности не может утечь через ссылку.

## Контент-адресуемое хранилище (CAS)

Бинарная плоскость — это контент-адресуемое хранилище, а не `spatie/laravel-medialibrary`
([ADR-0008](/adr/0008-media-library-decision)). Три сменяемых сервиса
(`config('vaulter.cas.*')`):

- **`ContentStore`** (`Sha256ContentStore`) — стейджит байты в seekable-временный файл и вычисляет
  контент-адрес `sha256` из застейдженного файла (никогда из живого стрима или присланного клиентом
  хеша).
- **`StorageDriver`** (`FlysystemStorageDriver`) — байтовая плоскость поверх Flysystem-диска, с
  условным `put` (`If-None-Match: *` на S3) и `temporaryUrl`.
- **`BlobStore`** — гонкоустойчивый оркестратор жизненного цикла. Живость — это *существование
  ребра* в `v_file_version_blobs`; `ref_count` — перестраиваемый кеш. Сборка мусора двухфазная и
  lock-first (`PENDING → COMMITTED → TOMBSTONED → DELETED`) с воскрешением затомбстоненного блоба,
  который конкурентная загрузка снова зареференсила.

Физический ключ **квалифицирован по scope** — `<scope>/ab/cd/<sha256>`, где `<scope>` следует
`config('vaulter.dedup.scope')` (`tenant` по умолчанию), поэтому сборка мусора одного scope никогда
не может удалить байты другого scope ([ADR-0012](/adr/0012-dedup-scope)). См.
[рецепт сборки мусора блобов](/ru/guide/recipes#blob-gc) и
[рецепт кастомного storage-драйвера](/ru/guide/recipes#s3-disk).

## Совместное редактирование в реальном времени

Yjs — источник истины; TipTap JSON — производная проекция. Транспорт сменяем за
`CollaborationDriver` ([ADR-0001](/adr/0001-collaboration-transport)):

- **`reverb`** (по умолчанию) — Yjs поверх Laravel Reverb. Vue-клиент присоединяется к **приватному**
  broadcast-каналу `vaulter.documents.{nodeId}`; коллбэк channel-auth пропускает его через
  `AccessResolver` (≥ `View` для подключения). Никакого лишнего сервиса, никакого room JWT.
- **`hocuspocus`** — внешний Node-хаб (`hocuspocus-server/`). Клиент подключается с подписанным
  room JWT; хаб коллбэчит в вебхуки `collab/authenticate` + `collab/store`.

## Точки расширения

Каждый шов, к которому подключается хост, и где он задокументирован:

| Контракт | Привязка через | Задокументировано в |
|---|---|---|
| `Contracts\Access\HostRoleDriver` | `config('vaulter.drivers.permission')` | [Рецепты — кастомный драйвер прав](/ru/guide/recipes#permission-driver) |
| `Contracts\Access\PermissionResolver` (легаси) | `Vaulter::resolvePermissionsWith()` / `config('vaulter.permission_resolver')` | [ADR-0002](/adr/0002-authorization-delegation) |
| `Contracts\Access\AzgardGuard` | привязка в контейнере (адаптер хоста) | [Устранение неполадок — морфы Azgard](/ru/guide/troubleshooting#azgard-morphs) |
| `Contracts\Cas\StorageDriver` / `ContentStore` / `BlobStore` | `config('vaulter.cas.*')` | [Рецепты — переход на S3](/ru/guide/recipes#s3-disk) |
| `Contracts\Collaboration\CollaborationDriver` | `config('vaulter.collaboration.drivers')` | [ADR-0001](/adr/0001-collaboration-transport) |
| `Contracts\Handlers\FileHandler` | `config('vaulter.handlers')` / `Vaulter::registerHandler()` | [Рецепты — кастомный обработчик формата](/ru/guide/recipes#format-handler) |
| `Contracts\Handlers\DocumentExporter` | `config('vaulter.exporters')` / `Vaulter::registerExporter()` | [ADR-0004](/adr/0004-content-format) |
| `Contracts\Sharing\ShareTokenGenerator` | `config('vaulter.share.token_generator')` | [ADR-0013](/adr/0013-two-sharing-channels) |
| `Contracts\Uploads\UploadProtocolHandler` | тег контейнера | [ADR-0006](/adr/0006-content-addressed-blobs-gc) |
| `Contracts\IdStrategy` | `config('vaulter.ids.strategies')` | [ADR-0011](/adr/0011-id-strategy) |
| `config('vaulter.models.*')` | конфиг | [ADR-0003](/adr/0003-embeddability-data-model) |
| Доменные `Events` | подписчик событий | подцепить аудит/уведомления без зависимости Vaulter от них |

## Реестр решений

Обоснование каждого выбора выше живёт в [ADR](/adr/). Полный набор:

| ADR | Что решает | Статус |
|---|---|---|
| [0001](/adr/0001-collaboration-transport) | Транспорт совместного редактирования — `CollaborationDriver`, Reverb по умолчанию против Hocuspocus. | accepted |
| [0002](/adr/0002-authorization-delegation) | Авторизация делегирована хосту (`PermissionResolver`). | refined by 0007 |
| [0003](/adr/0003-embeddability-data-model) | Встраиваемость через сменяемые модели + двойной тип ключа. | superseded by 0009 / 0011 |
| [0004](/adr/0004-content-format) | Контент в TipTap JSON + append-only ограниченная история версий. | accepted |
| [0005](/adr/0005-unified-node-tree) | Одно дерево-список-смежности (`v_nodes`) для папок/файлов/документов/ярлыков. | accepted |
| [0006](/adr/0006-content-addressed-blobs-gc) | Контент-адресуемые блобы + двухфазная гонкоустойчивая сборка мусора. | accepted |
| [0007](/adr/0007-pluggable-authorization) | `HostRoleDriver` (подключаемый) + `AccessResolver` (собственный), вето-запрет. | accepted |
| [0008](/adr/0008-media-library-decision) | Собственный CAS вместо `spatie/laravel-medialibrary`. | accepted |
| [0009](/adr/0009-storage-panels) | Storage Panels — config-first, конверты с нулём миграций. | accepted |
| [0010](/adr/0010-php-npm-monorepo-release) | Один репозиторий, два пакетных менеджера, два канала релиза. | accepted |
| [0011](/adr/0011-id-strategy) | `bigint | ulid | uuid7` за швом `IdStrategy` (ULID по умолчанию). | accepted |
| [0012](/adr/0012-dedup-scope) | Пер-тенантная дедупликация по умолчанию + квалифицированные по scope ключи хранения. | accepted |
| [0013](/adr/0013-two-sharing-channels) | ACL по идентичности и bearer-ссылки оцениваются как отдельные проходы. | accepted |

Две цепочки замещения, которые стоит держать в голове: **0003 → 0009 / 0011** (механика
встраиваемости) и **0002 → 0007** (делегирование авторизации).

## Дальше

- [Рецепты](/ru/guide/recipes) — проработанные, готовые к копипасту интеграции этих швов.
- [Устранение неполадок](/ru/guide/troubleshooting) — режимы отказа, которые создаёт эта
  архитектура, и как их избежать.
- [Конфигурация](/ru/guide/configuration) — каждый config-ключ, по секциям.
