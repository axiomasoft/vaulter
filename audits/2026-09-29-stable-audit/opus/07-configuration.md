# 07 — Конфигурация

Решение: [D26](02-decisions.md#d26). Принципы:

1. **Один файл на пакет-владелец**: `vaulter.php` (core), `vaulter-media.php`, `vaulter-drive.php`,
   `vaulter-documents.php`, `vaulter-azgard.php`, `vaulter-filament.php`. Пакет читает только свои ключи и ключи core.
2. **Ключи, влияющие на схему БД, — только в `vaulter.php`**: соединение, префикс таблиц, стратегия ID, тип
   ключей хоста, tenancy. Их изменение после миграции обнаруживает doctor.
3. **Один канонический ключ на понятие**; устаревшие ключи переводит `ConfigNormalizer` с
   `E_USER_DEPRECATED` до 1.0; при конфликте «старый и новый заданы по-разному» — `InvalidConfigurationException`.
4. **Типизированный доступ**: каждый пакет имеет readonly-класс `Configuration\<Pkg>Config`, который **читает
   через** `Illuminate\Contracts\Config\Repository` (без кэширования — тесты с `config()->set()` продолжают
   работать) и возвращает типы. Прямой `config('vaulter…')` вне `Configuration\*` запрещён arch-тестом.
5. **Валидация при boot**: ошибки ключей безопасности — исключение во всех окружениях; наблюдаемость — warning в лог
   и null-реализация (как сейчас). Полный список проверок — doctor.
6. Замыкания в конфиге запрещены; всё — FQCN или скаляры.

## 1. `config/vaulter.php` (core)

```php
return [
    'database' => [
        'connection' => env('VAULTER_DB_CONNECTION'),   // null = соединение по умолчанию
        'table_prefix' => 'v_',
    ],

    'ids' => [
        'strategy' => 'ulid',                            // bigint | ulid | uuid7 | ключ из strategies
        'strategies' => [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ],
        'host_keys' => 'string',                         // string (varchar 64) | bigint | uuid | ulid — D25
    ],

    'tenancy' => [
        'enabled' => false,
        'resolver' => null,                              // FQCN TenantResolver; обязателен при enabled
    ],

    'profiles' => [
        'default' => DefaultProfile::class,
        'providers' => [],                               // FQCN ProfileProvider
    ],

    'features' => [                                      // глобальные флаги ядра; профиль может только сузить
        'grants' => true,
        'activity' => true,
        'webhooks' => true,                              // было 'reliability'
        'share_links' => false,
        'public_listing' => false,
    ],

    'access' => [
        'auth_guard' => null,                            // guard для «текущего пользователя»; null = по умолчанию
        'permission_driver' => 'database',
        'permission_drivers' => [
            'database' => DatabasePermissionDriver::class,
            'gate' => GatePermissionDriver::class,
            'spatie' => SpatiePermissionDriver::class,
            'null' => NullPermissionDriver::class,       // запрещён в production
        ],
        'gate' => [ /* abilities map, super_ability, create_ability — как сейчас */ ],
        'spatie' => [ /* permissions map, super/create permission, teams, subject types — как сейчас */ ],
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),   // версия /v1 добавляется пакетом
        'middleware' => [
            'authenticated' => ['api', 'auth:sanctum'],
            'public' => ['api'],
            'hub' => ['api'],
        ],
        'rate_limits' => [
            'public' => 60,                              // в минуту, по IP
            'uploads' => 120,                            // в минуту, по actor
        ],
    ],

    'listing' => [
        'max_per_page' => 200,
        'scan_budget' => 1000,                           // D12
    ],

    'sharing' => [
        'token_generator' => RandomShareTokenGenerator::class,
        'url_base' => '/share',
        'prefix_length' => 8,
        'default_ttl_hours' => 72,
        'max_ttl_hours' => 720,
    ],

    'downloads' => [
        'inline_mime_types' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'],
    ],

    'webhooks' => [ /* timeout, max_attempts, backoff, disable_after_failures, headers, retention_days,
                       retry_claim_seconds, manage_ability, delivery.* — как сейчас; + 'event_id_header' */ ],

    'handlers' => [],                                    // дополнительные FileHandler FQCN (builtin — через тег)

    'drives' => [
        'delete_with_owner' => false,                    // D27, trait HasDrives
    ],

    'schedule' => [
        'enabled' => true,
        'webhooks_relay' => 'everyMinute',
        'webhooks_retry' => 'everyMinute',
        'webhooks_prune' => 'daily',
        'uploads_sweep' => 'hourly',
        'trash_purge' => 'daily',
        'blobs_gc' => 'daily',
        'blobs_reconcile' => 'weekly',
    ],

    'observability' => [ /* enabled, recorder, tracing.enabled, tracing.recorder — как сейчас */ ],

    'models' => [
        'drive' => Drive::class,
        'node' => Node::class,
        'file' => File::class,
        'file_revision' => FileRevision::class,
        'file_revision_blob' => FileRevisionBlob::class,
        'blob' => Blob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,
        'activity' => Activity::class,
        'outbox' => Outbox::class,
        'webhook_endpoint' => WebhookEndpoint::class,
        'webhook_delivery' => WebhookDelivery::class,
    ],
];
```

Удалены: `table_names` (остаётся префикс; upgrade-миграция читает старую карту, чтобы найти таблицы),
`tenant.column` (колонка всегда `tenant_id`; upgrade-миграция переименовывает кастомную), `features.blobs`,
`features.versions`, `features.uploads` (→ drive), `features.comments/document_versions/spreadsheets/collaboration`
(→ documents), `panels`, `default_panel`, `migrations.path`, `tiptap.*` (→ documents), `dedup`, `gc`,
`storage.default_disk` (→ media), `trash`, `uploads`, `drive.*` (→ drive), `versions`, `max_versions`,
`enabled_document_types`, `max_document_size_kb`, `exporters`, `collaboration` (→ documents),
`share_token_generator`, `id_type`, `drivers.permission`, `azgard.*` (→ azgard).

## 2. `config/vaulter-media.php`

```php
return [
    'disk' => env('VAULTER_DISK', 'local'),              // диск по умолчанию; профиль может переопределить
    'dedup' => [
        'scope' => 'tenant',                             // global | tenant | drive (было 'storage')
    ],
    'gc' => [
        'grace_hours' => 24,
        'orphan_grace_hours' => 24,
    ],
    'staging' => [
        'directory' => null,                             // null = sys_get_temp_dir()
    ],
    'content_store' => Sha256ContentStore::class,
    'storage_driver' => FlysystemStorageDriver::class,
    'blob_store' => BlobStore::class,
];
```

`dedup.scope` больше не влияет на схему (уникальность по `scope_key`, D20): смена области после миграции
безопасна — новые загрузки дедуплицируются в новой области, старые blobs остаются валидными.

## 3. `config/vaulter-drive.php`

```php
return [
    'uploads' => [
        'max_file_size' => 5 * 1024 ** 3,                // единый лимит для всех путей (было uploads.max_bytes + drive.max_upload_kb)
        'allowed_mime_types' => [],                      // пусто = любые; поддерживает 'image/*'
        'blocked_extensions' => [],
        'reject_mime_mismatch' => true,                  // D18
        'resumable' => [
            'enabled' => true,                           // было features.uploads
            'protocols' => ['tus', 's3'],
            'chunk_size' => 8 * 1024 ** 2,
            'max_chunk_size' => 64 * 1024 ** 2,
            'session_ttl_hours' => 24,
            'max_active_sessions_per_actor' => 20,
            'metadata' => [
                'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
                'max_value_length' => 512,
            ],
        ],
        'scan' => [
            'required' => false,
            'scanner' => null,                           // FQCN ContentScanner
            'queue' => null,
        ],
    ],
    'names' => [
        'max_length' => 255,                             // байты UTF-8; разделители и управляющие символы запрещены всегда
        'normalize_unicode' => true,                     // NFC при наличии ext-intl
    ],
    'trash' => [
        'retention_days' => 30,
    ],
    'quota' => [
        'default_bytes' => null,
    ],
    'models' => [
        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
    ],
];
```

Удалён `drive.name_rules.no_separators_pattern`: пользовательская регулярка для безопасности имён — ловушка;
правило фиксировано в коде.

## 4. `config/vaulter-documents.php`

```php
return [
    'types' => [
        RichTextDocumentType::class,
        MarkdownDocumentType::class,
        // SpreadsheetDocumentType::class,              // opt-in (было features.spreadsheets)
    ],
    'default_type' => 'vaulter/rich-text',
    'content' => [
        'max_size_kb' => 5120,
    ],
    'revisions' => [
        'limit' => 50,                                   // без keep_forever; профиль может переопределить
        'autosave_debounce_seconds' => 30,
    ],
    'comments' => [
        'enabled' => true,
    ],
    'exporters' => [
        HtmlExporter::class,
        MarkdownExporter::class,
    ],
    'tiptap' => [
        'extra_nodes' => [],
        'extra_marks' => [],
        'extra_attributes' => [],
        'url_attributes' => [],
    ],
    'collaboration' => [
        'enabled' => false,
        'driver' => 'hocuspocus',
        'hub' => [
            'url' => env('VAULTER_COLLAB_URL'),          // wss:// обязателен в production (doctor)
            'app_id' => env('VAULTER_COLLAB_APP_ID'),
            'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
            'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
            'token_ttl' => 3600,
            'timestamp_tolerance' => 300,
        ],
        'broadcast' => [
            'channel_prefix' => 'vaulter.documents',     // уведомления (комментарии) через Laravel broadcasting
        ],
    ],
    'models' => [
        'document' => Document::class,
        'document_revision' => DocumentRevision::class,
        'node_comment' => NodeComment::class,
    ],
];
```

«Документы включены» = пакет установлен. Маршруты можно выключить общим `vaulter.routes.enabled`.

## 5. `config/vaulter-azgard.php`

```php
return [
    'panel_id' => env('VAULTER_AZGARD_PANEL'),
    'permissions' => [ /* ability → AzGuard permission, как сейчас в vaulter.azgard.permissions */ ],
    'create_permission' => 'documents.create',
];
```

Драйвер регистрирует ключ `azgard` в `vaulter.access.permission_drivers` из своего provider'а.

## 6. Карта устаревших ключей (ConfigNormalizer)

| Устаревший ключ | Канонический |
|---|---|
| `vaulter.connection` | `vaulter.database.connection` |
| `vaulter.table_prefix` | `vaulter.database.table_prefix` |
| `vaulter.id_type`, `vaulter.ids.default` | `vaulter.ids.strategy` |
| `vaulter.tenant.enabled` | `vaulter.tenancy.enabled` |
| `vaulter.panels`, `vaulter.default_panel` | `vaulter.profiles.providers`, `vaulter.profiles.default` (классы-панели не совместимы — ошибка с подсказкой `vaulter:make:profile`) |
| `vaulter.features.reliability` | `vaulter.features.webhooks` |
| `vaulter.drivers.permission` | `vaulter.access.permission_driver` |
| `vaulter.permission_drivers` | `vaulter.access.permission_drivers` |
| `vaulter.gate.*`, `vaulter.spatie.*` | `vaulter.access.gate.*`, `vaulter.access.spatie.*` |
| `vaulter.azgard.*` | `vaulter-azgard.*` |
| `vaulter.route_prefix` | `vaulter.routes.prefix` |
| `vaulter.middleware` | `vaulter.routes.middleware.authenticated` (+ предупреждение: публичные маршруты теперь в `public`) |
| `vaulter.rate_limits.*.per_minute` | `vaulter.routes.rate_limits.*` |
| `vaulter.share_token_generator`, `vaulter.share.*` | `vaulter.sharing.*` |
| `vaulter.downloads.inline_mime_whitelist` | `vaulter.downloads.inline_mime_types` |
| `vaulter.storage.default_disk` | `vaulter-media.disk` |
| `vaulter.dedup.scope` (`storage`) | `vaulter-media.dedup.scope` (`drive`) |
| `vaulter.gc.*` | `vaulter-media.gc.*` |
| `vaulter.cas.*` | `vaulter-media.*` |
| `vaulter.uploads.max_bytes`, `vaulter.drive.max_upload_kb` | `vaulter-drive.uploads.max_file_size` (меньшее из двух; конфликт — предупреждение) |
| `vaulter.drive.allowed_mime` | `vaulter-drive.uploads.allowed_mime_types` |
| `vaulter.uploads.*` (chunk, ttl, sessions, tus, metadata) | `vaulter-drive.uploads.resumable.*` |
| `vaulter.features.uploads` | `vaulter-drive.uploads.resumable.enabled` |
| `vaulter.trash.retention_days` | `vaulter-drive.trash.retention_days` |
| `vaulter.drive.name_rules.max_length` | `vaulter-drive.names.max_length` |
| `vaulter.enabled_document_types` | `vaulter-documents.types` (перевод ключей: richtext/table/template → rich-text, markdown → markdown) |
| `vaulter.features.spreadsheets` | добавить `SpreadsheetDocumentType` в `vaulter-documents.types` |
| `vaulter.max_document_size_kb` | `vaulter-documents.content.max_size_kb` |
| `vaulter.versions.max`, `vaulter.max_versions` | `vaulter-documents.revisions.limit` |
| `vaulter.versions.autosave_debounce_seconds` | `vaulter-documents.revisions.autosave_debounce_seconds` |
| `vaulter.features.comments` | `vaulter-documents.comments.enabled` |
| `vaulter.features.collaboration` | `vaulter-documents.collaboration.enabled` |
| `vaulter.collaboration.*` | `vaulter-documents.collaboration.*` (`drivers.hocuspocus.secret` → `hub.jwt_secret`, `webhook_secret` → `hub.webhook_secret`) |
| `vaulter.tiptap.*` | `vaulter-documents.tiptap.*` |
| `vaulter.exporters` (map format → FQCN) | `vaulter-documents.exporters` (список FQCN) |
| `vaulter.models.storage/file_version/file_version_blob` | `vaulter.models.drive/file_revision/file_revision_blob` |
| `vaulter.models.upload_session/upload_part` | `vaulter-drive.models.*` |
| `vaulter.models.node_comment/node_document_version` | `vaulter-documents.models.node_comment/document_revision` |

## 7. Проверки при boot

| Проверка | Реакция |
|---|---|
| Драйвер прав: ключ существует, класс реализует `PermissionDriver` | исключение |
| Драйвер `null` в production | исключение (как сейчас) |
| `tenancy.enabled` без валидного `resolver` | исключение |
| `url_guard`, `token_generator`, модели (подкласс базовой), провайдеры профилей | исключение |
| Уникальные ключи профилей, существует `profiles.default`, профиль не включает выключенное глобально | исключение |
| Реестры: дубликаты ключей handler/type/exporter/lifecycle | исключение |
| `vaulter-documents.default_type` зарегистрирован | исключение |
| Коллаборация включена: оба секрета заданы и различны | исключение в production, warning иначе |
| Recorder наблюдаемости не найден | warning + null-реализация |
| Нет auth-middleware в `routes.middleware.authenticated` | doctor error (`--production`) |
| Диск существует в `filesystems.disks` | doctor error |
