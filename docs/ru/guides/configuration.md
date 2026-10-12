---
title: Конфигурация
description: Конфиги по пакетам-владельцам, типизированный доступ и переход с опубликованных ключей 0.2.
outline: deep
---

# Конфигурация

Каждый установленный пакет владеет своим файлом и типизированным API конфигурации (AD26).
Опубликуйте нужные файлы, например:

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-documents-config
```

| Пакет | Файл | Тег публикации | Класс конфигурации |
|---|---|---|---|
| Core | `config/vaulter.php` | `vaulter-config` | `Vaulter\Configuration\VaulterConfig` |
| Media | `config/vaulter-media.php` | `vaulter-media-config` | `Vaulter\Media\Configuration\MediaConfig` |
| Drive | `config/vaulter-drive.php` | `vaulter-drive-config` | `Vaulter\Drive\Configuration\DriveConfig` |
| Documents | `config/vaulter-documents.php` | `vaulter-documents-config` | `Vaulter\Documents\Configuration\DocumentsConfig` |
| AzGuard | `config/vaulter-azgard.php` | `vaulter-azgard-config` | `Vaulter\Azgard\Configuration\AzgardConfig` |
| Filament | `config/vaulter-filament.php` | `vaulter-filament-config` | `Vaulter\Filament\Configuration\FilamentConfig` |

Привязки задаются строками FQCN. Замыкания отклоняются при boot, поэтому
`php artisan config:cache` безопасен. Значения по умолчанию — в [справочнике ключей](/ru/reference/config).

## Core: база данных, ID, профили и доступ

Только core владеет настройками схемы: `database.connection`, `database.table_prefix`,
`ids.strategy`, `ids.host_keys` и `tenancy.enabled`. Соединение `null` использует соединение
Laravel по умолчанию. Имя таблицы состоит из префикса и канонического имени сущности:
`DatabaseConfig::table('drives')` по умолчанию возвращает `v_drives`. Карта `table_names`
нужна только для обновления: `DatabaseConfig::legacyTable()` ищет по ней старые физические таблицы.

```php
// config/vaulter.php
'database' => [
    'connection' => env('VAULTER_DB_CONNECTION'),
    'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
],
'ids' => [
    'strategy' => env('VAULTER_ID_STRATEGY', 'ulid'), // bigint | ulid | uuid7
    'host_keys' => env('VAULTER_HOST_KEYS', 'string'), // string | bigint | uuid | ulid
    'strategies' => [
        'bigint' => \Vaulter\Schema\AutoIncrementIds::class,
        'ulid' => \Vaulter\Schema\UlidIds::class,
        'uuid7' => \Vaulter\Schema\Uuid7Ids::class,
    ],
],
'profiles' => [
    'default' => \Vaulter\Profiles\DefaultProfile::class,
    'providers' => [\App\Vaulter\Profiles\ProjectProfile::class],
],
'features' => [
    'grants' => true,
    'activity' => true,
    'webhooks' => true,
    'share_links' => false,
    'public_listing' => false,
],
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
    'permission_drivers' => [
        'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
        'gate' => \Vaulter\Access\Drivers\GatePermissionDriver::class,
        'spatie' => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
        'null' => \Vaulter\Testing\NullPermissionDriver::class,
    ],
    // Здесь сохраняются карты abilities/permissions в gate.* и spatie.*.
],
'handlers' => [],
```

`ids.host_keys = string` создаёт `varchar(64)` независимо от ID сущностей Vaulter.
Выбирайте bigint/uuid/ulid только если все ключи хоста соответствуют этому типу.
Изменение настроек схемы после миграций требует обновления схемы; см. UPGRADE.md.

`tenancy.enabled` включает интеграцию с тенантностью хоста. Колонка всегда называется
`tenant_id`; скоуп тенанта реализуйте в своих подклассах моделей.
См. [Мультитенантность](/ru/concepts/tenancy).

В `profiles.providers` и `profiles.default` укажите FQCN `ProfileProvider`.
Сгенерируйте провайдер командой `php artisan vaulter:make:profile project`.
Ключи профилей должны быть уникальны, а профиль по умолчанию — зарегистрирован.
См. [Профили Drive](/ru/concepts/drives-and-profiles).

### Модели {#models}

Подмены моделей core: `models.drive`, `node`, `file`, `file_revision`,
`file_revision_blob`, `blob`, `grant`, `node_share`, `activity`, `outbox`,
`webhook_endpoint` и `webhook_delivery`. Каждая модель наследует соответствующую базовую.
Модели Drive и Documents находятся в собственных корнях конфигурации.

Драйвер по умолчанию — автономный `database`. Карты Gate и Spatie находятся в
`access.gate.*` и `access.spatie.*`. Установленный мост AzGuard регистрирует
`access.permission_drivers.azgard`; настройки моста остаются в `vaulter-azgard.*`.
Открытый драйвер `null` запрещён в production. См. [Драйверы прав](/ru/extending/permission-drivers).

`handlers` содержит дополнительные FQCN `FileHandler`. Провайдеры регистрируют встроенные
обработчики через тег контейнера `vaulter.file_handlers`: пустой список сохраняет
preview/download и обработчики установленного Documents.

## Маршруты и публичные ссылки

```php
// config/vaulter.php
'routes' => [
    'prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
    'middleware' => [
        'authenticated' => ['api', 'auth:sanctum'],
        'public' => ['api'],
        'hub' => ['api'],
    ],
    'rate_limits' => ['public' => 60, 'uploads' => 120],
],
'sharing' => [
    'token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,
    'url_base' => '/share',
    'prefix_length' => 8,
],
```

Стек `authenticated` защищает маршруты акторов; публичные ссылки используют `public`,
подписанные callback-запросы коллаборации — `hub`. Метрики запросов добавляются автоматически.
Старый `middleware` влияет только на `authenticated`; публичный стек настройте отдельно.
Установите Sanctum или замените `auth:sanctum` аутентификацией хоста.

`features.webhooks` управляет поведением вебхуков. Настройки доставки сохраняются в
`webhooks.*`: URL guard, таймауты, повторы и сроки хранения. `listing.scan_budget`,
`downloads.inline_mime_types` и `observability.*` также принадлежат core.

## Media: контент-адресуемое хранилище

```php
// config/vaulter-media.php
'disk' => env('VAULTER_DISK', 'local'),
'dedup' => ['scope' => env('VAULTER_DEDUP_SCOPE', 'tenant')], // tenant | drive | off
'gc' => ['grace_hours' => 24, 'orphan_grace_hours' => 24],
'content_store' => \Vaulter\Media\Cas\Sha256ContentStore::class,
'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
'blob_store' => \Vaulter\Media\Cas\BlobStore::class,
```

CAS-бакет должен быть без версионирования: удалением управляет двухфазный GC.
Минимальное окно GC — один час. Каноническая область дедупликации — `drive`
(раньше `storage`). `global` удалён (PLAN2.D49): провайдер отказывает при загрузке с
`InvalidConfigurationException`; существующие глобальные блобы переносит
`vaulter:blobs:repair-scopes`. Дедупликация использует сохранённый `scope_key`; смена области
сохраняет старые блобы и влияет на новые загрузки.
См. [Хранилище и блобы](/ru/concepts/storage-and-blobs).

## Drive: загрузки, имена и корзина

```php
// config/vaulter-drive.php
'uploads' => [
    'max_file_size' => 5_368_709_120, // байты; 0 = без ограничения
    'allowed_mime_types' => [],
    'resumable' => [
        'enabled' => true,
        'chunk_size' => 8_388_608,
        'max_chunk_size' => 67_108_864,
        'session_ttl_hours' => 24,
        'max_active_sessions_per_actor' => 20,
        'metadata' => [
            'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
            'max_value_length' => 512,
        ],
    ],
],
'trash' => ['retention_days' => 30],
'models' => [
    'upload_session' => \Vaulter\Drive\Models\Uploads\UploadSession::class,
    'upload_part' => \Vaulter\Drive\Models\Uploads\UploadPart::class,
],
```

`max_file_size` — общий лимит в байтах для обычных и резюмируемых загрузок.
Нулевой лимит чанка или активных сессий отключает это ограничение.
`resumable.enabled` управляет резюмируемыми маршрутами; таблицы загрузок существуют
независимо от флага. Правила имён, лимиты папок и лимит размера файла профиля задаёт политика
диска профиля (см. [Drive → Имена, лимиты, избранное и свойства](/ru/concepts/nodes-and-revisions));
`vaulter-drive.names.max_length`, `vaulter.drive.name_rules.max_length` и
`vaulter.extra_data.enabled` устарели и игнорируются (`E_USER_DEPRECATED`).
См. [Загрузки](/ru/guides/uploads) и [Корзина](/ru/guides/trash).

## Documents: содержимое, ревизии, комментарии и коллаборация

```php
// config/vaulter-documents.php
'content' => ['max_size_kb' => 5120],
'revisions' => ['limit' => 50, 'checkpoint_interval_seconds' => 300],
'comments' => ['enabled' => true],
'exporters' => [\Vaulter\Documents\Export\HtmlExporter::class, \Vaulter\Documents\Export\MarkdownExporter::class],
'collaboration' => [
    'enabled' => false,
    'driver' => 'hocuspocus',
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
'models' => [
    'document_revision' => \Vaulter\Documents\Models\Revisions\DocumentRevision::class,
    'node_comment' => \Vaulter\Documents\Models\Comments\NodeComment::class,
],
```

Установка `vaulter-documents` включает редактирование документов и ревизии.
Блобы и ревизии файлов включены всегда; `features.blobs`, `features.versions` и
`features.document_versions` удалены. Старые `false` игнорируются с предупреждением.
Комментарии теперь по умолчанию **включены**. Для отключения задайте
`vaulter-documents.comments.enabled = false`. Опубликованный старый
`features.comments = false` переносится и сохраняет отключение.
Коллаборация остаётся опциональной. При её включении оба секрета хаба должны быть заданы
и различаться: иначе production boot завершается исключением, а другие окружения пишут warning.

`exporters` — список имён классов экспортёров (карта формат → FQCN из 0.2 ещё читается, с предупреждением об устаревании). Расширения allow-list TipTap переезжают в
`vaulter-documents.tiptap.*`. `vaulter.enabled_document_types` и
`vaulter.features.spreadsheets` превращаются в список классов
[типов документов](/ru/extending/document-types) `vaulter-documents.types`
(`richtext`/`table`/`template` → `RichTextDocumentType`, `markdown` → `MarkdownDocumentType`,
`spreadsheets = true` → `SpreadsheetDocumentType`) с предупреждением об устаревании. Если в
списке нет формата rich text (`['markdown']`), значение по умолчанию
`vaulter-documents.default_type` (`vaulter/rich-text`) не зарегистрировано, и загрузка падает, пока
не задан зарегистрированный ключ, например `vaulter/markdown`.
См. [Совместное редактирование](/ru/guides/collaboration) и [Экспорт](/ru/guides/export).

## Типизированный доступ

Классы конфигурации — `final readonly` singleton поверх
`Illuminate\Contracts\Config\Repository`. В сервисах используйте конструкторную
инъекцию; в моделях, миграциях и статических контекстах — `app(...)`.

```php
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;

final readonly class ExportPolicy
{
    public function __construct(
        private VaulterConfig $config,
        private DocumentsConfig $documents,
    ) {}

    public function revisionLimit(): int
    {
        return $this->documents->revisionLimit();
    }
}

$database = app(VaulterConfig::class)->database();
$table = $database->table('drives');
$connection = $database->connection();
```

`VaulterConfig` даёт секции `database()`, `ids()`, `tenancy()`, `access()`, `routes()`,
`listing()`, `sharing()`, `downloads()`, `webhooks()`, `observability()` и методы
`model(string)`, `featureEnabled(string)`, `profileProviders()`, `defaultProfile()`,
`handlers()`, `extraDataEnabled()`. Классы модулей дают собственные модели и настройки.
Каждый вызов заново читает репозиторий: `config()->set()` в тестах сразу меняет результат.
Код пакетов читает ключи Vaulter только в `Configuration`; границу проверяют arch-тесты.

## Переход с опубликованной конфигурации 0.2

Перед boot провайдеров `ConfigNormalizer` переносит старые ключи установленных пакетов.
Каждый устаревший ключ даёт один `E_USER_DEPRECATED`; повторная нормализация не повторяет
предупреждения. Если явно изменённый канонический ключ отличается от переведённого
старого значения, возникает `Vaulter\Exceptions\Configuration\InvalidConfigurationException`.
Равные значения дают только deprecation. Ключи отсутствующих модулей не затрагиваются.
Поддержка устаревших ключей заканчивается в 1.0.

| Устаревший ключ | Канонический ключ |
|---|---|
| `vaulter.connection` | `vaulter.database.connection` |
| `vaulter.table_prefix` | `vaulter.database.table_prefix` |
| `vaulter.id_type / vaulter.ids.default` | `vaulter.ids.strategy` |
| `vaulter.tenant.enabled` | `vaulter.tenancy.enabled` |
| `vaulter.panels / vaulter.default_panel` | `vaulter.profiles.providers / vaulter.profiles.default` |
| `vaulter.features.reliability` | `vaulter.features.webhooks` |
| `vaulter.drivers.permission` | `vaulter.access.permission_driver` |
| `vaulter.permission_drivers` | `vaulter.access.permission_drivers` |
| `vaulter.gate.* / vaulter.spatie.*` | `vaulter.access.gate.* / vaulter.access.spatie.*` |
| `vaulter.azgard.*` | `vaulter-azgard.*` |
| `vaulter.route_prefix` | `vaulter.routes.prefix` |
| `vaulter.middleware` | `vaulter.routes.middleware.authenticated` |
| `vaulter.rate_limits.{public,uploads}.per_minute` | `vaulter.routes.rate_limits.{public,uploads}` |
| `vaulter.share_token_generator / vaulter.share.token_generator` | `vaulter.sharing.token_generator` |
| `vaulter.share.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` | `vaulter.sharing.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` |
| `vaulter.downloads.inline_mime_whitelist` | `vaulter.downloads.inline_mime_types` |
| `vaulter.storage.default_disk` | `vaulter-media.disk` |
| `vaulter.dedup.scope` | `vaulter-media.dedup.scope` |
| `vaulter.gc.*` | `vaulter-media.gc.*` |
| `vaulter.cas.{content_store,storage_driver,blob_store}` | `vaulter-media.{content_store,storage_driver,blob_store}` |
| `vaulter.uploads.max_bytes / vaulter.drive.max_upload_kb` | `vaulter-drive.uploads.max_file_size` |
| `vaulter.drive.allowed_mime` | `vaulter-drive.uploads.allowed_mime_types` |
| `vaulter.uploads.chunk_size` | `vaulter-drive.uploads.resumable.chunk_size` |
| `vaulter.uploads.max_chunk_bytes` | `vaulter-drive.uploads.resumable.max_chunk_size` |
| `vaulter.uploads.session_ttl_hours` | `vaulter-drive.uploads.resumable.session_ttl_hours` |
| `vaulter.uploads.max_active_sessions` | `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` |
| `vaulter.uploads.metadata.*` | `vaulter-drive.uploads.resumable.metadata.*` |
| `vaulter.features.uploads / vaulter.uploads.tus.enabled` | `vaulter-drive.uploads.resumable.enabled` |
| `vaulter.trash.retention_days` | `vaulter-drive.trash.retention_days` |
| `vaulter.max_document_size_kb` | `vaulter-documents.content.max_size_kb` |
| `vaulter.max_versions / vaulter.versions.max` | `vaulter-documents.revisions.limit` |
| `vaulter.features.comments` | `vaulter-documents.comments.enabled` |
| `vaulter.features.collaboration` | `vaulter-documents.collaboration.enabled` |
| `vaulter.collaboration.driver` | `vaulter-documents.collaboration.driver` |
| `vaulter.collaboration.channel_prefix` | `vaulter-documents.collaboration.broadcast.channel_prefix` |
| `vaulter.collaboration.webhook_secret` | `vaulter-documents.collaboration.hub.webhook_secret` |
| `vaulter.collaboration.webhook_timestamp_tolerance` | `vaulter-documents.collaboration.hub.timestamp_tolerance` |
| `vaulter.collaboration.drivers.hocuspocus.secret` | `vaulter-documents.collaboration.hub.jwt_secret` |
| `vaulter.collaboration.drivers.hocuspocus.{url,token_ttl}` | `vaulter-documents.collaboration.hub.{url,token_ttl}` |
| `vaulter.tiptap.*` | `vaulter-documents.tiptap.*` |
| `vaulter.exporters` | `vaulter-documents.exporters` |
| `vaulter.models.storage` | `vaulter.models.drive` |
| `vaulter.models.file_version / vaulter.models.file_version_blob` | `vaulter.models.file_revision / vaulter.models.file_revision_blob` |
| `vaulter.models.upload_session / vaulter.models.upload_part` | `vaulter-drive.models.upload_session / vaulter-drive.models.upload_part` |
| `vaulter.models.node_comment` | `vaulter-documents.models.node_comment` |
| `vaulter.models.node_document_version` | `vaulter-documents.models.document_revision` |
| `vaulter-filament.resources.storage` | `vaulter-filament.resources.drive` |

`id_type = id` превращается в `ids.strategy = bigint`; `ulid` остаётся `ulid`.
Старые классы панелей нужно переписать как `ProfileProvider`; исключение подскажет
`vaulter:make:profile`. Лимиты загрузок сравниваются в байтах (`max_upload_kb × 1024`):
различающиеся старые лимиты дают warning и используется меньший.
Старый `false` для uploads или tus отключает `resumable.enabled`.

Когда заданы оба старых ID-ключа, `ids.default` сохраняет приоритет над `id_type`.
Для старых алиасов share-токена пользовательское значение побеждает поставляемый дефолт;
два разных пользовательских значения вызывают `InvalidConfigurationException`.
Оставьте один `sharing.token_generator`.

`tenant.column`, `drive.name_rules.no_separators_pattern` и `migrations.path` игнорируются
с deprecation: имя колонки тенанта и правила разделителей теперь фиксированы.
`versions.autosave_debounce_seconds` и `vaulter-documents.revisions.autosave_debounce_seconds`
игнорируются так же: чекпоинты документа неизменяемы и следуют
`vaulter-documents.revisions.checkpoint_interval_seconds` (PLAN2.D34).
Оставьте `table_names` только на время обновления физических таблиц.

Старую публикацию Media перенесите в `config/vaulter-media.php`. Нормализатор переводит
точные строки FQCN встроенных классов на старых ключах и сохраняет пользовательские FQCN.
PHP-импорты и объявления собственных классов требуют канонических имён; PHP-алиасов нет.
См. [UPGRADE.md](https://github.com/axioma-studio/vaulter/blob/main/UPGRADE.md).
