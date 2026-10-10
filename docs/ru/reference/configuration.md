---
title: Справочник по конфигурации
description: Исчерпывающий справочник по каждому ключу config/vaulter.php — значения по умолчанию, ENV-переменные и допустимые значения.
outline: deep
---

# Справочник по конфигурации

Каждый ключ `config/vaulter.php`, разбитый по секциям. Опубликуйте файл командой:

```bash
php artisan vendor:publish --tag=vaulter-config
```

Колонки каждой таблицы: путь до конфиг-ключа (относительно `vaulter.`, если явно не указан корень модуля), ENV-переопределение (если оно есть), поставляемое значение по умолчанию и то, чем ключ управляет. Значения читаются через собственные `Configuration\*Config` классы пакетов, внедряемые в сервисы вместо прямых вызовов `config()`.

::: warning Безопасно для кеша конфига
Каждая привязка — это FQCN или обычный скаляр, никаких замыканий. Конфиг безопасно прогонять через `php artisan config:cache`.
:::

## Модели — `models.*`

Сменяемые Eloquent-модели. Направьте любую запись на собственный подкласс, чтобы добавить поведение (ULID-ключи, изоляция по тенанту, аудит). Vaulter всегда резолвит модели через эту карту.

| Ключ | По умолчанию | Владелец |
|---|---|---|
| `models.drive` | `Vaulter\Models\Drive\Drive` | core |
| `models.node` | `Vaulter\Models\Drive\Node` | core |
| `models.file` | `Vaulter\Models\Drive\File` | core |
| `models.file_revision` | `Vaulter\Models\Drive\FileRevision` | core |
| `models.blob` | `Vaulter\Models\Cas\Blob` | core |
| `models.file_revision_blob` | `Vaulter\Models\Drive\FileRevisionBlob` | core |
| `models.grant` | `Vaulter\Models\Access\NodeGrant` | core |
| `models.node_share` | `Vaulter\Models\Sharing\NodeShare` | core |
| `vaulter-drive.models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axioma-studio/vaulter-drive` |
| `vaulter-drive.models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axioma-studio/vaulter-drive` |
| `vaulter-documents.models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axioma-studio/vaulter-documents` |
| `vaulter-documents.models.document_revision` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axioma-studio/vaulter-documents` |
| `models.activity` | `Vaulter\Models\Activity\Activity` | core |
| `models.outbox` | `Vaulter\Models\Webhooks\Outbox` | core |
| `models.webhook_endpoint` | `Vaulter\Models\Webhooks\WebhookEndpoint` | core |
| `models.webhook_delivery` | `Vaulter\Models\Webhooks\WebhookDelivery` | core |

Модели модулей находятся в `vaulter-drive.models.*` и `vaulter-documents.models.*`; они не мержатся в карту моделей core.

## Database — `database.*`

Таблицы используют канонические имена с префиксом `database.table_prefix` (по умолчанию `v_`).
`database.connection` (`VAULTER_DB_CONNECTION`, по умолчанию `null`) выбирает соединение.
`table_names` читается только upgrade-миграциями через `DatabaseConfig::legacyTable()`.

## Первичные ключи — `ids.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `ids.strategy` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | FQCN, реализующий `IdStrategy` |

Выбирайте `ids.strategy` напрямую. Старый `id_type` нормализуется (`id` → `bigint`, `ulid` → `ulid`); конфликт с изменённым каноническим значением вызывает исключение.

## Profiles — `profiles.*`

| Ключ | По умолчанию |
|---|---|
| `profiles.providers` | `[]` |
| `profiles.default` | `Vaulter\Profiles\DefaultProfile` |

Оба ключа принимают FQCN `ProfileProvider`. Ключи профилей уникальны; профиль по умолчанию зарегистрирован.

## Фичи — `features.*`

Глобальные переключатели фич, читаются через `VaulterConfig::featureEnabled()`; панель может переопределить любую из них для себя.

| Ключ | По умолчанию | Отвечает за |
|---|---|---|
| `features.grants` | `true` | Гранты на узлы (ACL) |
| `features.activity` | `true` | Журнал активности |
| `features.share_links` | `false` | Bearer-ссылки на узлы (share) |
| `features.public_listing` | `false` | Публичный листинг |
| `features.webhooks` | `true` | Подписчик outbox + маршруты эндпойнтов |
| `features.changes` | `true` | Журнал изменений drive за `DriveHandle::changes()` |

## Обработчики документов — `handlers`

Список FQCN `FileHandler`, которые принадлежащий core `Vaulter\Handlers\FileHandlerRegistry` резолвит по цепочке mime → расширение → возможность → приоритет, с откатом к null-объекту `DownloadOnlyHandler`. Две базовые записи — СОБСТВЕННЫЙ дефолт core (VLT-CANON P0.4/D10 — резолвятся даже без установленного `vaulter-documents`). Обработчики обслуживают только файлы: узлы-документы представляются своим [типом документа](/ru/guide/document-types).

| Встроенные обработчики через тег | Владелец |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |

Добавьте новый формат файлов одним классом + записью здесь (или через `Vaulter::handlers()->register()`).

## Тенантность — `tenancy.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | Интеграция тенантности хоста; колонка всегда `tenant_id`. Изоляцию задаёт модель хоста |

См. [Auth → Мультитенантность](/ru/guide/multi-tenancy).

## Префикс таблиц — `database.table_prefix`

| Ключ | Env | По умолчанию |
|---|---|---|
| `database.table_prefix` | `VAULTER_TABLE_PREFIX` | `v_` |

Префикс для каждой таблицы Vaulter (его читают и миграции, и модели), чтобы пакет сосуществовал с таблицами хоста.

## Авторизация

### Drive ACL — `access.permission_driver`, `access.permission_drivers.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `database` | ключ из `access.permission_drivers` |
| `access.permission_drivers.database` | — | `Vaulter\Access\Drivers\DatabasePermissionDriver` | автономный — авторизует чисто по строкам грантов |
| `access.permission_drivers.gate` | — | `Vaulter\Access\Drivers\GatePermissionDriver` | драйвер Laravel Gate |
| `access.permission_drivers.spatie` | — | `Vaulter\Access\Drivers\SpatiePermissionDriver` | драйвер spatie/laravel-permission |
| `access.permission_drivers.azgard` | — | `Vaulter\Azgard\AzgardPermissionDriver` | регистрируется provider установленного `vaulter-azgard` |
| `access.permission_drivers.null` | — | `Vaulter\Testing\NullPermissionDriver` | открытый, **только не для продакшена** |

::: danger Null-драйвер в продакшене
Драйвер `null` открыт и завязан на окружение. Валидация продакшен-конфига отвергает панель, привязанную к нему, — привяжите реальный драйвер до деплоя.
:::

### Карта драйвера Gate — `access.gate.*`

Используется только когда `access.permission_driver = 'gate'`. Маппит `GrantLevel` на право (ability) хостового Gate; наивысший разрешённый уровень становится ролевым грантом актора.

| Ключ | Env | По умолчанию |
|---|---|---|
| `access.gate.abilities.reader` | — | `vaulter.view` |
| `access.gate.abilities.commenter` | — | `vaulter.comment` |
| `access.gate.abilities.writer` | — | `vaulter.edit` |
| `access.gate.abilities.organizer` | — | `vaulter.organize` |
| `access.gate.abilities.owner` | — | `vaulter.manage` |
| `access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Карта драйвера Spatie — `access.spatie.*`

Используется только когда `access.permission_driver = 'spatie'` **и** установлен spatie/laravel-permission.

| Ключ | Env | По умолчанию |
|---|---|---|
| `access.spatie.permissions.reader` | — | `vaulter.view` |
| `access.spatie.permissions.commenter` | — | `vaulter.comment` |
| `access.spatie.permissions.writer` | — | `vaulter.edit` |
| `access.spatie.permissions.organizer` | — | `vaulter.organize` |
| `access.spatie.permissions.owner` | — | `vaulter.manage` |
| `access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `access.spatie.role_subject_type` | — | `role` |
| `access.spatie.team_subject_type` | — | `team` |

`access.spatie.teams` используется как запасной вариант только тогда, когда собственный `config('permission.teams')` хоста не задан. `role_subject_type`/`team_subject_type` — это morph-алиасы, на которые адресуются строки грантов роли/команды.

### Карта драйвера AzGuard — `vaulter-azgard.*`

Отдельный пакет: `composer require axioma-studio/vaulter-azgard`. Настройки ниже
читаются из `config/vaulter-azgard.php`, не из конфига ядра.

| Ключ | По умолчанию |
|---|---|
| `vaulter-azgard.panel_id` | `null` (панель определяется из ключа разрешения) |

Используется только когда `access.permission_driver = 'azgard'`. Ориентирована на действия — маппит право (ability) Vaulter на ключ разрешения AzGuard.

| Ключ | По умолчанию |
|---|---|
| `vaulter-azgard.permissions.view` | `documents.view` |
| `vaulter-azgard.permissions.download` | `documents.download` |
| `vaulter-azgard.permissions.comment` | `documents.comment` |
| `vaulter-azgard.permissions.edit` | `documents.update` |
| `vaulter-azgard.permissions.create` | `documents.create` |
| `vaulter-azgard.permissions.move` | `documents.update` |
| `vaulter-azgard.permissions.copy` | `documents.update` |
| `vaulter-azgard.permissions.trash` | `documents.delete` |
| `vaulter-azgard.permissions.delete` | `documents.delete` |
| `vaulter-azgard.permissions.restore` | `documents.delete` |
| `vaulter-azgard.permissions.share` | `documents.manage` |
| `vaulter-azgard.permissions.manage` | `documents.manage` |

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-azgard.create_permission` | `VAULTER_AZGARD_CREATE_PERMISSION` | `documents.create` |

Мост поставляет реальный адаптер guard. Хост должен реализовать `AzgardBackedUser`
и согласовать ULID-morph — см. [Драйверы прав](/ru/guide/permission-drivers).

## Контент-адресуемое хранилище блобов — `vaulter-media.*`

Сменяемые CAS-сервисы, все привязаны в провайдере и резолвятся через контейнер.

| Ключ | По умолчанию | Контракт |
|---|---|---|
| `vaulter-media.content_store` | `Vaulter\Media\Cas\Sha256ContentStore` | `ContentStore` |
| `vaulter-media.storage_driver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | `StorageDriver` |
| `vaulter-media.blob_store` | `Vaulter\Media\Cas\BlobStore` | `BlobStore` |

## Дедупликация — `vaulter-media.dedup.scope`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `vaulter-media.dedup.scope` | `VAULTER_DEDUP_SCOPE` | `tenant` | `global` \| `tenant` \| `drive` |

Граница дедупликации. Уникальность задаётся сохранёнными `scope_key` и хешем. `global` разделяет байты на всю установку, `tenant` — на тенанта, `drive` — внутри одного диска. Смена области влияет на новые загрузки; старые блобы сохраняют свою область.

## Сборка мусора блобов — `vaulter-media.gc.grace_hours`

| Ключ | Env | По умолчанию | Примечания |
|---|---|---|---|
| `vaulter-media.gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | Grace-окно двухфазной сборки мусора; нижняя граница 1ч |

Блоб, у которого кеш счётчика ссылок достигает нуля, помечается tombstone с `delete_after_at = now + grace`; его байты выметаются только после того, как grace-окно прошло. См. [Рецепты → Сборка мусора блобов](/ru/guide/recipes).

## CAS-диск хранилища — `vaulter-media.disk`

| Ключ | Env | По умолчанию | Примечания |
|---|---|---|---|
| `vaulter-media.disk` | `VAULTER_DISK` | `local` | Flysystem-диск по умолчанию для канонических CAS-объектов, когда `v_storages.disk` блоба не закрепляет собственный |

::: warning Держите CAS-бакет без версионирования
Никогда не используйте S3 lifecycle/versioning как механизм удаления блобов.
:::

## Публичные ссылки (share)

### Bearer-ссылки на узлы (share) — `sharing.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `sharing.token_generator` | — | `Vaulter\Support\RandomShareTokenGenerator` | `ShareTokenGenerator`, чеканящий сырой bearer-токен (хранится только его sha256-хеш) |
| `sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | Публичная база, из которой собирается URL публичной ссылки |
| `sharing.prefix_length` | — | `8` | Сколько ведущих символов сырого токена хранится как несекретный `token_prefix` |

См. [Использование → Публичные ссылки](/ru/guide/sharing).

## Корзина — `vaulter-drive.trash.retention_days`

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

Окно хранения, по истечении которого поддерево узла из корзины окончательно принудительно удаляется командой `php artisan vaulter:purge-trash`.

## Диски — `vaulter.drives.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | `HasDrives`: удаление модели-владельца удаляет её диски (system-актор `owner-deleted`); при `SoftDeletes` считается только `forceDeleted` |

См. [Drive · жизненный цикл](/ru/guide/drive).

## Загрузки — `vaulter-drive.uploads.*`

| Ключ | По умолчанию | Env |
|---|---|---|
| `vaulter-drive.uploads.max_file_size` | `5368709120` байт | `VAULTER_UPLOAD_MAX_BYTES` |
| `vaulter-drive.uploads.allowed_mime_types` | `[]` | — |
| `vaulter-drive.uploads.resumable.enabled` | `true` | `VAULTER_UPLOAD_TUS_ENABLED` |
| `vaulter-drive.uploads.resumable.chunk_size` | `8388608` байт | `VAULTER_UPLOAD_CHUNK_SIZE` |
| `vaulter-drive.uploads.resumable.max_chunk_size` | `67108864` байт | `VAULTER_UPLOAD_MAX_CHUNK_BYTES` |
| `vaulter-drive.uploads.resumable.session_ttl_hours` | `24` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `20` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | `512` | — |

## Надёжность — вебхуки — `webhooks.*`

Транзакционный outbox + доставка, подписанная по [Standard Webhooks](/guide/webhooks#headers-and-signature) (at-least-once, расписание повторов, DLQ).

**Удалено в 1.0** — опубликованный конфиг с любым из этих ключей не загрузится: `InvalidConfigurationException` назовёт замену. `webhooks.max_attempts` и `webhooks.backoff` (→ `retry_schedule`), `webhooks.disable_after_failures` (→ `disable_after_days`), `webhooks.signature_header`, `webhooks.timestamp_header`, `webhooks.event_header` (заголовки зафиксированы стандартом).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `webhooks.timeout` | — | `15` | Таймаут HTTP-запроса (секунды) на каждый POST доставки |
| `webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Пауза (секунды) после n-й неудачной попытки; попыток = элементов + 1 (около трёх суток), последняя неудача делает доставку мёртвой |
| `webhooks.retry_jitter` | — | `0.1` | Доля (0–0,5), на которую случайно меняется каждая пауза повтора |
| `webhooks.disable_after_days` | — | `5` | Сколько суток непрерывных сбоев выключают эндпоинт (`0` — никогда) |
| `webhooks.secret_rotation_overlap` | — | `86400` | Сколько секунд прежний секрет продолжает подписывать после ротации (не более 604800) |
| `webhooks.retention_days` | — | `30` | Возраст, после которого завершённые строки outbox + терминальные доставки можно вычищать (строки в `pending` — никогда) |
| `webhooks.relay.claim_seconds` | — | `60` | Сколько relay-воркер держит аренду строки outbox; строку упавшего воркера продолжают после неё. Столько же ожидающая доставка ждёт свой job, прежде чем retry sweep поставит её заново |
| `webhooks.relay.fanout_chunk` | — | `100` | Сколько эндпоинтов пишется за одну транзакцию раскрытия (целое 1..1000; иное значение валит загрузку) |
| `webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | Право (ability) Gate, охраняющее поверхность управления эндпойнтами; `null` разрешает любому аутентифицированному пользователю |

Флаг `features.webhooks` включает подписчик outbox и маршруты эндпойнтов; таблицы существуют всегда. См. [Рецепты → Надёжность и вебхуки](/ru/guide/recipes).

## Журнал изменений — `changes.*`

[Журнал изменений](/guide/changes) каждого drive, который синхронизирующие клиенты читают через `DriveHandle::changes()`. `features.changes` (по умолчанию `true`) включает запись; таблицы существуют всегда. Выключение и повторное включение оставляет пропуск, который закрывает только сброс (`vaulter:changes:reset`).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `changes.retention_days` | — | `30` | Сколько дней запись доступна для чтения; `vaulter:changes:prune` удаляет более старые, а курсор позади них истекает (`410 cursor_expired`). Целое ≥ 1, иное значение валит загрузку |
| `changes.max_page_size` | — | `1000` | Наибольший `limit` одной страницы `changes()->list()` (целое 1..10000, иное значение валит загрузку) |

## API-маршруты — `routes.*`

| Ключ | Env | По умолчанию |
|---|---|---|
| `routes.prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |

## Версии документов-узлов — `vaulter-documents.revisions.*`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `vaulter-documents.revisions.limit` | `50` | Хранимых чекпоинтов на документ до обрезки (`0` = без ограничений) |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | `300` | Сколько секунд рабочее состояние может опережать последний автосохранённый чекпоинт (`0` — чекпоинт на каждое эффективное сохранение). Ключ 0.2 `autosave_debounce_seconds` устарел и игнорируется |

См. [Использование → Версии](/ru/guide/documents).

## Документы — `vaulter-documents.types`, `default_type`, `content.max_size_kb`

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.types` | `[RichTextDocumentType::class, MarkdownDocumentType::class]` — зарегистрированные [типы документов](/ru/guide/document-types); добавьте `SpreadsheetDocumentType::class` или свой |
| `vaulter-documents.default_type` | `'vaulter/rich-text'` — тип, который использует `create()`, если тип не указан; обязан быть зарегистрирован |
| `vaulter-documents.content.max_size_kb` | `5120` — лимит кодека, у которого `maxSizeBytes()` равен `null` |

Ключи 0.2 нормализуются с предупреждением об устаревании: `vaulter.enabled_document_types`
(`richtext`/`table`/`template` → `RichTextDocumentType`, `markdown` → `MarkdownDocumentType`)
и `vaulter.features.spreadsheets = true` (добавляет `SpreadsheetDocumentType`) превращаются в
`vaulter-documents.types`. Перенесённый список заменяет список по умолчанию, поэтому без формата
rich text (`['markdown']`) тип `vaulter/rich-text` не зарегистрирован, и приложение не загрузится,
пока `vaulter-documents.default_type` не назовёт зарегистрированный тип (`vaulter/markdown`).

## Форматы экспорта — `vaulter-documents.exporters`

Список имён классов `DocumentExporter`, разрешаемых по паре (тип документа, формат). Два
экспортёра на одну пару падают при загрузке; набор замораживается после загрузки.

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.exporters` | `[HtmlExporter::class, MarkdownExporter::class]` (`Vaulter\Documents\Export\*`) |

Добавьте свой (PDF, DOCX, …) здесь или через `Documents::exporters()->register()`. См. [Рецепты → Свой экспорт](/ru/guide/recipes).

## Совместное редактирование — `vaulter-documents.collaboration.*`

Yjs — источник истины; TipTap JSON — производная проекция. Транспорт сменяем за драйвером.

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter-documents.collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | `hocuspocus` |
| `vaulter-documents.collaboration.broadcast.channel_prefix` | — | `vaulter.documents` | Пространство имён комнаты; комната всегда `prefix.nodeId` |
| `vaulter-documents.collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | Общий секрет, который Node-хаб предъявляет на S2S webhook-эндпойнтах. Нужен для подписи тела вебхука при включённой коллаборации |
| `vaulter-documents.collaboration.hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | WebSocket-URL хаба |
| `vaulter-documents.collaboration.hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | `null` | Ключ подписи room-JWT **и** ключ, по которому вебхуки проверяют JWT |
| `vaulter-documents.collaboration.hub.token_ttl` | — | `3600` | TTL room-JWT (секунды) |

См. [Совместное редактирование → Обзор](/ru/guide/collaboration) и [Совместное редактирование → Сервер](/ru/guide/collaboration).

## См. также

- [Фасад и Manager](/ru/reference/facade) — точки входа в рантайме
- [Справочник контрактов](/ru/reference/contracts) — интерфейсы, которые реализуют эти FQCN
- [Справочник команд](/ru/reference/commands) — планируемые команды обслуживания

## Дополнительные настройки

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.comments.enabled` | `true` |
| `vaulter-documents.collaboration.enabled` | `false` |
| `vaulter-documents.collaboration.hub.timestamp_tolerance` | `300` секунд |
| `vaulter-documents.tiptap.extra_nodes` | `[]` |
| `vaulter-documents.tiptap.extra_marks` | `[]` |
| `vaulter-documents.tiptap.extra_attributes` | `[]` |
| `vaulter-documents.tiptap.url_attributes` | `[]` |
| `vaulter-media.gc.orphan_grace_hours` | `24` (`VAULTER_ORPHAN_GRACE_HOURS`, откат к `VAULTER_GC_GRACE_HOURS`) |
| `ids.host_keys` | `string` (`VAULTER_HOST_KEYS`) |
| `listing.scan_budget` | `1000` |
| `routes.middleware.authenticated` | `['api', 'auth:sanctum']` |
| `routes.middleware.public` | `['api']` |
| `routes.middleware.hub` | `['api']` |
| `routes.rate_limits.public` | `60` (`VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE`) |
| `routes.rate_limits.uploads` | `120` (`VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE`) |
| `sharing.default_ttl_hours` | `72` (`VAULTER_SHARE_DEFAULT_TTL_HOURS`) |
| `sharing.max_ttl_hours` | `720` (`VAULTER_SHARE_MAX_TTL_HOURS`) |
| `downloads.inline_mime_types` | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` |
| `observability.enabled` | `false` (`VAULTER_OBSERVABILITY_ENABLED`) |
| `observability.recorder` | `null` (`VAULTER_OBSERVABILITY_RECORDER`) |
| `observability.tracing.enabled` | `false` (`VAULTER_TRACING_ENABLED`) |
| `observability.tracing.recorder` | `null` (`VAULTER_TRACING_RECORDER`) |
| `webhooks.retry_claim_seconds` | `60` |
| `webhooks.delivery.url_guard` | `Vaulter\Webhooks\DefaultOutboundUrlGuard` |
| `webhooks.delivery.allowed_schemes` | `['http', 'https']` |
| `webhooks.delivery.blocked_ip_ranges` | `null` (закрытые встроенные диапазоны) |
| `webhooks.delivery.allowed_ip_ranges` | `[]` |
| `webhooks.delivery.connect_timeout` | `2` секунд |
| `webhooks.delivery.max_response_bytes` | `65536` байт |

Документы включены при установке пакета; блобы и ревизии файлов включены всегда.
Комментарии теперь включены по умолчанию; старый `features.comments = false` переносится.
Карта экспортёров сохраняет формат → FQCN. `enabled_document_types` и `features.spreadsheets`
стали `vaulter-documents.types`. См. [карту миграции](/ru/guide/configuration#переход-с-опубликованной-конфигурации-0-2).
