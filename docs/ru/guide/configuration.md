---
title: Конфигурация
description: Разбор config/vaulter.php по секциям — модели, имена таблиц, стратегия идентификаторов, панели, фичи, драйверы прав, CAS, шеринг, загрузки, вебхуки и совместное редактирование.
outline: deep
---

# Конфигурация

Опубликуйте конфиг:

```bash
php artisan vendor:publish --tag=vaulter-config
```

Он ложится в `config/vaulter.php`. Эта страница проходит по каждой секции. Конфиг **безопасен
для кеширования** — каждая сменная привязка задаётся строкой FQCN, а не замыканием, — поэтому
`php artisan config:cache` работает.

## Модели

Каждая сущность Vaulter резолвится через эту карту, поэтому любую можно указать на свой
наследник (ULID-ключи, скоуп тенанта, аудит и т.д.):

```php
'models' => [
    // Сущности Vaulter Drive
    'storage'    => \Vaulter\Models\Drive\Drive::class,
    'node'       => \Vaulter\Models\Drive\Node::class,
    'file'       => \Vaulter\Models\Drive\File::class,
    'blob'       => \Vaulter\Models\Cas\Blob::class,
    'grant'      => \Vaulter\Models\Access\NodeGrant::class,
    'node_share' => \Vaulter\Models\Sharing\NodeShare::class,
    // …а также file_version, file_version_blob, activity, outbox,
    //    webhook_endpoint, webhook_delivery
],
```

::: info Модели, которыми владеют Drive/Documents
`upload_session` / `upload_part` принадлежат `axioma-studio/vaulter-drive`; модели
`node_comment` / `node_document_version`, привязанные к узлу, принадлежат
`axioma-studio/vaulter-documents`. Ни одна из них не поставляет значение по умолчанию
здесь — каждый пакет мержит свою запись через собственный
`config/vaulter-drive.php` / `config/vaulter-documents.php` и бросает исключение при
резолве без установленного пакета. Легаси-модели, привязанные к `document_id`
(`document`, `version`, `folder`, `permission`, `comment`, `share`), были полностью
исключены — см. [ADR-0014](/adr/0014-legacy-docvault-strangler).
:::

Vaulter route-биндит `{storage}` и `{node}` к тому, что здесь указано, поэтому наследник хоста
продолжает резолвиться.

## Имена таблиц и префикс

Каждая таблица именуется `{table_prefix}{base}`. Меняйте префикс глобально или переименуйте
таблицу одной сущности в `table_names`:

```php
'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'), // → v_storages, v_nodes, …
'table_names' => [
    'storages' => 'storages',
    'nodes'    => 'nodes',
    'blobs'    => 'blobs',
    // …по одному базовому имени на сущность
],
```

## Стратегия первичного ключа (`ids`)

Стратегия определяет тип колонки каждого первичного/morph-ключа. **По умолчанию `ulid`**,
чтобы morph-ключи совпадали с экосистемой Corex/AzGuard.

```php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid | uuid7 | bigint
    'strategies' => [
        'bigint' => \Vaulter\Schema\AutoIncrementIds::class,
        'ulid'   => \Vaulter\Schema\UlidIds::class,
        'uuid7'  => \Vaulter\Schema\Uuid7Ids::class,
    ],
],
```

| Стратегия | Колонка | Когда |
|---|---|---|
| `ulid` | строка ULID 26 симв. | **По умолчанию.** Сортируемые, согласованные morph-ключи. |
| `uuid7` | нативный `uuid` (Postgres) | Нужны нативные uuid-колонки. |
| `bigint` | автоинкремент big integer | Легаси / целочисленные ключи. |

::: warning Легаси `id_type`
Старый бинарный ключ `id_type` (`'id'` / `'ulid'`) сохранён для обратной совместимости. Когда
задан `ids.default`, **он побеждает**; иначе стратегия выводится из `id_type` (`id → bigint`,
`ulid → ulid`). Новым установкам стоит использовать `ids.default`. См.
[ADR-0011](/adr/0011-id-strategy).
:::

## Storage Panels

Панель — именованный конфиг-конверт, привязанный к сущности поверх общей схемы `v_*`, по
умолчанию **без миграций**. Перечислите FQCN своих провайдеров:

```php
'panels' => [
    \App\Vaulter\Profiles\ProjectDriveProfile::class,
],
'default_panel' => \Vaulter\Profiles\DefaultProfile::class,

'migrations' => [
    'path' => null, // legacy-ключ; создание профиля его больше не использует
],
```

Сгенерировать панель: `php artisan vaulter:make:profile <name>`. Подробно —
[Storage Panels](/ru/concepts/storage-panels).

## Фичи

Флаги фич уровня установки (панель может переопределить любой из них для себя):

```php
'features' => [
    'blobs'             => true,
    'versions'          => true,
    'grants'            => true,
    'activity'          => true,
    'uploads'           => true,
    'share_links'       => false,
    'document_versions' => false, // включает v_node_document_versions + API документ-узлов
    'spreadsheets'      => false, // включает xlsx-обработчик UniverHandler
    'public_listing'    => false,
    'reliability'       => true,  // включает схему и маршруты outbox + вебхуков
],
```

Флаг гейтит **и** схему, **и** маршруты/подписчики, поэтому выключение оставляет миграцию и
эндпоинты полностью вне приложения.

## Обработчики документов

Реестр, маршрутизирующий узел к нужному обработчику формата (mime → расширение → возможности →
приоритет), с откатом на «только-скачать». Новый формат — один класс плюс строка:

```php
'handlers' => [
    \Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    \Vaulter\Handlers\Builtin\PreviewHandler::class,
    \Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

## Драйверы авторизации

Drive ACL комбинирует собственный `AccessResolver` со **сменным** драйвером ролей хоста.
Драйвер по умолчанию `database` полностью автономен (без зависимости от хоста):

```php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],

'permission_drivers' => [
    'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
    'gate'     => \Vaulter\Access\Drivers\GatePermissionDriver::class,
    'spatie'   => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
    'azgard'   => \Vaulter\Access\Drivers\AzgardPermissionDriver::class,
    'null'     => \Vaulter\Testing\NullPermissionDriver::class, // только не-прод
],
```

У каждого не-`database`-драйвера своя подсекция, сопоставляющая уровень/способность Vaulter
имени права хоста (`gate.*`, `spatie.*`, `azgard.*`). Полный разбор — в
[Драйвере ролей хоста](/ru/concepts/authorization).

::: danger Драйвер null
`null` — открытый драйвер, доступный **только** вне прода. Валидация конфига отвергает панель
на нём в проде — привяжите реальный драйвер перед деплоем.
:::

Также есть отдельный **легаси**-резолвер на документном пути:

```php
'permission_resolver' => null, // FQCN реализации Contracts\Access\PermissionResolver или null
```

## Контент-адресуемое хранилище (CAS)

Сменный конвейер блобов, область дедупликации, окно отсрочки GC и диск по умолчанию:

```php
'cas' => [
    'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
    'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
    'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,
],

'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global | tenant | storage
],

'gc' => [
    'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24), // минимум 1 час
],

'storage' => [
    'default_disk' => env('VAULTER_DISK', 'local'),
],
```

::: warning Бакет без версионирования
CAS-бакет должен быть **без версионирования** — не используйте lifecycle/versioning S3 как
механизм удаления блобов; это конфликтует с двухфазным GC.
:::

## Шеринг

Bearer-ссылки — отдельный публичный канал; хранится только `sha256`-хеш токена:

```php
'share_token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,

'share' => [
    'token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,
    'url_base'        => env('VAULTER_SHARE_URL_BASE', '/share'),
    'prefix_length'   => 8,
],
```

Двухканальный дизайн — [ADR-0013](/adr/0013-two-sharing-channels).

## Корзина

Окно до безвозвратной очистки поддерева удалённого узла командой
`php artisan vaulter:purge-trash`:

```php
'trash' => [
    'retention_days' => env('VAULTER_TRASH_RETENTION_DAYS', 30),
],
```

## Резюмируемые загрузки

Большие файлы грузятся резюмируемо (tus на любом диске; S3-multipart на объектных хранилищах),
затем собираются через тот же гонко-безопасный `BlobStore.commit`:

```php
'uploads' => [
    'max_bytes'          => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120), // 5 ГиБ
    'chunk_size'         => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608),    // 8 МиБ
    'session_ttl_hours'  => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),
    'tus' => ['enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true)],
],
```

Флаг фичи `uploads` гейтит и схему, и группу маршрутов.

## Надёжность — outbox и вебхуки

Транзакционный outbox стейджит каждое доменное событие, затем relay разворачивает его в
подписанные доставки вебхуков (at-least-once с backoff + DLQ):

```php
'webhooks' => [
    'timeout'                => 5,
    'max_attempts'           => 5,
    'backoff'                => [10, 30, 60, 300, 1800],
    'disable_after_failures' => 10,
    'signature_header'       => 'X-Vaulter-Signature',
    'timestamp_header'       => 'X-Vaulter-Timestamp',
    'event_header'           => 'X-Vaulter-Event',
    'retention_days'         => 30,
    'manage_ability'         => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
],
```

Гейтится флагом фичи `reliability`.

## Документы и версии

```php
'max_versions'           => 50, // легаси-лимит версий документа (0 = без лимита)
'enabled_document_types' => ['richtext', 'table', 'markdown', 'template'],
'max_document_size_kb'   => 5120,

'versions' => [ // версии документ-узлов (M4a)
    'max'                       => 50,
    'autosave_debounce_seconds' => 30,
],

'drive' => [ // политика загрузки бинарных файлов (отдельно от лимита документов)
    'max_upload_kb' => null,  // null = откат к max_document_size_kb
    'allowed_mime'  => [],    // пусто = разрешено всё
],
```

## Экспорт

Сопоставление формата с `DocumentExporter` (добавьте PDF/DOCX/свой):

```php
'exporters' => [
    'html'     => \Vaulter\Export\HtmlExporter::class,
    'markdown' => \Vaulter\Export\MarkdownExporter::class,
],
```

## Маршруты

```php
'route_prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
'middleware'   => ['api'],
```

## Совместное редактирование

Источник истины — Yjs; транспорт сменяем за ключом `driver`:

```php
'collaboration' => [
    'driver'         => env('VAULTER_COLLAB_DRIVER', 'reverb'), // reverb | hocuspocus
    'channel_prefix' => 'vaulter.documents',
    'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
    'drivers' => [
        'reverb' => [
            'class'      => \Vaulter\Collaboration\ReverbCollaborationDriver::class,
            'connection' => env('VAULTER_COLLAB_CONNECTION', 'reverb'),
        ],
        'hocuspocus' => [
            'class'     => \Vaulter\Collaboration\HocuspocusCollaborationDriver::class,
            'url'       => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
            'app_id'    => env('VAULTER_COLLAB_APP_ID'),
            'secret'    => env('VAULTER_COLLAB_SECRET'),
            'token_ttl' => 3600,
        ],
    ],
],
```

- **`reverb`** (по умолчанию) — Yjs поверх Laravel Reverb, без доп. сервиса; Vue-клиент
  подключается к приватному broadcast-каналу `{channel_prefix}.{nodeId}`, гейтит `AccessResolver`.
- **`hocuspocus`** — внешний Node-хаб; клиент подключается с подписанным room-JWT, а хаб зовёт
  обратно в collab-вебхуки.

Решение — [ADR-0001](/adr/0001-collaboration-transport).

## Тенантность

Когда включено, миграции добавляют колонку тенанта. Vaulter **не** скоупит по ней за вас —
предоставьте tenant-aware-модель:

```php
'tenant' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
    'column'  => 'tenant_id',
],
```
