---
title: Конфигурация
description: Конфиги по пакетам-владельцам и типизированный доступ.
outline: deep
---

# Конфигурация

Каждый установленный пакет владеет своим файлом и типизированным API конфигурации (AD26).
Опубликуйте нужные файлы, например:

<!-- snippet: shell -->

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
`DatabaseConfig::table('drives')` по умолчанию возвращает `v_drives`.

<!-- snippet: config -->

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

<!-- snippet: config -->

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

Вебхуки — opt-in модуль: `vaulter.modules.webhooks` (по умолчанию `false`) подключает их маршруты,
команды, расписания и проверки doctor (см. [Модули](/ru/concepts/modules)). Настройки доставки сохраняются в
`webhooks.*`: URL guard, таймауты, повторы и сроки хранения. `listing.scan_budget`,
`downloads.inline_mime_types` и `observability.*` также принадлежат core.

## Media: контент-адресуемое хранилище

<!-- snippet: config -->

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
. `global` удалён (PLAN2.D49): провайдер отказывает при загрузке с
`InvalidConfigurationException`. Дедупликация использует сохранённый `scope_key`; смена области
сохраняет старые блобы и влияет на новые загрузки.
См. [Хранилище и блобы](/ru/concepts/storage-and-blobs).

## Drive: загрузки, имена и корзина

<!-- snippet: config -->

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
См. [Загрузки](/ru/guides/uploads) и [Корзина](/ru/guides/trash).

## Documents: содержимое, ревизии, комментарии и коллаборация

<!-- snippet: config -->

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
Блобы и ревизии файлов включены всегда.
Комментарии теперь по умолчанию **включены**. Для отключения задайте
`vaulter-documents.comments.enabled = false`.
Коллаборация остаётся опциональной. При её включении оба секрета хаба должны быть заданы
и различаться: иначе production boot завершается исключением, а другие окружения пишут warning.

`exporters` — список имён классов экспортёров. Расширения allow-list TipTap живут в
`vaulter-documents.tiptap.*`. `vaulter-documents.types` — список классов
[типов документов](/ru/extending/document-types). Если в списке нет типа rich text (`['markdown']`),
значение по умолчанию `vaulter-documents.default_type` (`vaulter/rich-text`) не зарегистрировано, и загрузка падает, пока
не задан зарегистрированный ключ, например `vaulter/markdown`.
См. [Совместное редактирование](/ru/guides/collaboration) и [Экспорт](/ru/guides/export).

## Типизированный доступ

Классы конфигурации — `final readonly` singleton поверх
`Illuminate\Contracts\Config\Repository`. В сервисах используйте конструкторную
инъекцию; в моделях, миграциях и статических контекстах — `app(...)`.

<!-- snippet: host -->

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

## Ключи, которых нет

Vaulter 1.0 читает только ключи опубликованных файлов `config/vaulter*.php`. Ключи прежних релизов разработки
не переводятся, не объявляются устаревшими и не вызывают предупреждений; неизвестный ключ игнорируется. Моста
миграции нет (см. [UPGRADE.md](https://github.com/axioma-studio/vaulter/blob/main/UPGRADE.md)).
Умолчания пакета по-прежнему заполняют недостающие вложенные ключи частично опубликованного файла, а заданная
хостом карта или список (`ids.strategies`, `handlers`, `vaulter-documents.types`, драйверы доступа) заменяет
умолчание, а не сливается с ним.
