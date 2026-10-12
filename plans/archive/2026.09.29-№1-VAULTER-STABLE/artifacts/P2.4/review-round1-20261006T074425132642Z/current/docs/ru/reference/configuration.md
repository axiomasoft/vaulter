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

Колонки каждой таблицы: путь до конфиг-ключа (относительно `vaulter.`), ENV-переопределение (если оно есть), поставляемое значение по умолчанию и то, чем ключ управляет. Значения читаются через хелпер `Vaulter\Support\Config`, который пакет использует повсеместно вместо прямых вызовов `config()`.

::: warning Безопасно для кеша конфига
Каждая привязка — это FQCN или обычный скаляр, никаких замыканий. Конфиг безопасно прогонять через `php artisan config:cache`.
:::

## Модели — `models.*`

Сменяемые Eloquent-модели. Направьте любую запись на собственный подкласс, чтобы добавить поведение (ULID-ключи, изоляция по тенанту, аудит). Vaulter всегда резолвит модели через эту карту.

| Ключ | По умолчанию | Владелец |
|---|---|---|
| `models.storage` | `Vaulter\Models\Drive\Drive` | core |
| `models.node` | `Vaulter\Models\Drive\Node` | core |
| `models.file` | `Vaulter\Models\Drive\File` | core |
| `models.file_version` | `Vaulter\Models\Drive\FileRevision` | core |
| `models.blob` | `Vaulter\Models\Cas\Blob` | core |
| `models.file_version_blob` | `Vaulter\Models\Drive\FileRevisionBlob` | core |
| `models.grant` | `Vaulter\Models\Access\NodeGrant` | core |
| `models.node_share` | `Vaulter\Models\Sharing\NodeShare` | core |
| `models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axioma-studio/vaulter-drive` |
| `models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axioma-studio/vaulter-drive` |
| `models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axioma-studio/vaulter-documents` |
| `models.node_document_version` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axioma-studio/vaulter-documents` |
| `models.activity` | `Vaulter\Models\Activity\Activity` | core |
| `models.outbox` | `Vaulter\Models\Webhooks\Outbox` | core |
| `models.webhook_endpoint` | `Vaulter\Models\Webhooks\WebhookEndpoint` | core |
| `models.webhook_delivery` | `Vaulter\Models\Webhooks\WebhookDelivery` | core |

::: info Модели, принадлежащие пакетам, резолвятся только когда пакет установлен
`upload_session` / `upload_part` мержатся в `vaulter.models` пакетом
`axioma-studio/vaulter-drive`; `node_comment` / `node_document_version` мержатся
пакетом `axioma-studio/vaulter-documents`. Core **не** поставляет значение по
умолчанию для этих четырёх ключей — резолв без установленного пакета-владельца
бросает исключение. Легаси-модели, привязанные к `document_id` (`document`,
`version`, `folder`, `permission`, `comment`, `share`), были полностью исключены —
см. [ADR-0014](/adr/0014-legacy-docvault-strangler).
:::

## Имена таблиц — `table_names.*`

Базовое имя таблицы для каждой сущности, вычисляется как `{table_prefix}{base}`. Позволяет переименовать таблицу отдельной сущности, не меняя глобальный префикс.

| Ключ | Базовое имя по умолчанию |
|---|---|
| `table_names.storages` | `storages` |
| `table_names.nodes` | `nodes` |
| `table_names.blobs` | `blobs` |
| `table_names.files` | `files` |
| `table_names.file_versions` | `file_versions` |
| `table_names.file_version_blobs` | `file_version_blobs` |
| `table_names.node_grants` | `node_grants` |
| `table_names.node_shares` | `node_shares` |
| `table_names.node_comments` | `node_comments` |
| `table_names.node_document_versions` | `node_document_versions` |
| `table_names.upload_sessions` | `upload_sessions` |
| `table_names.upload_parts` | `upload_parts` |
| `table_names.activity` | `activity` |
| `table_names.outbox` | `outbox` |
| `table_names.webhook_endpoints` | `webhook_endpoints` |
| `table_names.webhook_deliveries` | `webhook_deliveries` |

Легаси-таблицы, привязанные к `document_id` (`documents`, `document_folders`,
`document_versions`, `document_permissions`, `document_comments`, `document_shares`),
были удалены — см. [ADR-0014](/adr/0014-legacy-docvault-strangler).

## Первичные ключи — `id_type`, `ids.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `id_type` | `VAULTER_ID_TYPE` | `id` | `id` \| `ulid` — **легаси**, вытеснен `ids.default` |
| `ids.default` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | FQCN, реализующий `IdStrategy` |

Когда `ids.default` задан, побеждает он; иначе стратегия выводится из `id_type` (`id` → bigint, `ulid` → ulid). Зарегистрируйте свою стратегию, добавив ключ под `ids.strategies` с указанием на реализацию [`IdStrategy`](/ru/reference/contracts#identity).

## Storage Panels — `panels`, `default_panel`, `migrations.*`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `panels` | `[]` | Список FQCN `ProfileProvider`, которые переопределяют панель по умолчанию по её id |
| `default_panel` | `Vaulter\Profiles\DefaultProfile` | FQCN резервной панели |
| `migrations.path` | `null` | Legacy key retained for the configuration transition; unused by profile scaffolding. |

Сгенерируйте заготовку панели командой `php artisan vaulter:make:profile <name>`. См. [Концепции → Storage Panels](/ru/concepts/storage-panels).

## Фичи — `features.*`

Глобальные переключатели фич, читаются через `Config::featureEnabled()`; панель может переопределить любую из них для себя.

| Ключ | По умолчанию | Отвечает за |
|---|---|---|
| `features.blobs` | `true` | CAS-хранилище блобов |
| `features.versions` | `true` | Историю версий файлов |
| `features.grants` | `true` | Гранты на узлы (ACL) |
| `features.activity` | `true` | Журнал активности |
| `features.uploads` | `true` | Схему `v_upload_sessions`/`v_upload_parts` + резюмируемые маршруты |
| `features.share_links` | `false` | Bearer-ссылки на узлы (share) |
| `features.document_versions` | `false` | Схему `v_node_document_versions` + Actions/HTTP для документов-узлов (opt-in) |
| `features.spreadsheets` | `false` | `UniverHandler` за фича-флагом (редактирование xlsx) |
| `features.public_listing` | `false` | Публичный листинг |
| `features.reliability` | `true` | Схему `v_outbox`/`v_webhook_*` + подписчик outbox + маршруты эндпойнтов |

## Обработчики документов — `handlers`

Список FQCN `FileHandler`, которые принадлежащий core `Vaulter\Handlers\FileHandlerRegistry` резолвит по цепочке mime → расширение → возможность → приоритет, с откатом к null-объекту `DownloadOnlyHandler`. Две базовые записи — СОБСТВЕННЫЙ дефолт core (VLT-CANON P0.4/D10 — резолвятся даже без установленного `vaulter-documents`); `vaulter-documents` ДОБАВЛЯЕТ `TipTapHandler` своим слиянием конфига.

| Записи по умолчанию | Владелец |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |
| `Vaulter\Documents\Engines\Handlers\TipTapHandler` | vaulter-documents |

`UniverHandler` (xlsx) добавляется сервис-провайдером **только** когда включён `features.spreadsheets`. Добавьте новый формат одним классом + записью здесь (или через `Vaulter::handlers()->register()`).

## Тенантность — `tenant.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `tenant.enabled` | `VAULTER_TENANT_ENABLED` | `false` | Когда включено, миграции добавляют колонку тенанта. Vaulter **не** изолирует по ней сам — предоставьте модель, знающую о тенанте |
| `tenant.column` | — | `tenant_id` | Имя колонки тенанта |

См. [Auth → Мультитенантность](/ru/guide/multi-tenancy).

## Префикс таблиц — `table_prefix`

| Ключ | Env | По умолчанию |
|---|---|---|
| `table_prefix` | `VAULTER_TABLE_PREFIX` | `v_` |

Префикс для каждой таблицы Vaulter (его читают и миграции, и модели), чтобы пакет сосуществовал с таблицами хоста.

## Авторизация

### Легаси-резолвер — `permission_resolver`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `permission_resolver` | `null` | FQCN `PermissionResolver` или `null`, чтобы зарегистрировать его в рантайме через `Vaulter::resolvePermissionsWith()`. **Легаси**-путь документов |

### Drive ACL — `drivers.permission`, `permission_drivers.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `drivers.permission` | `VAULTER_PERMISSION_DRIVER` | `database` | ключ из `permission_drivers` |
| `permission_drivers.database` | — | `Vaulter\Access\Drivers\DatabasePermissionDriver` | автономный — авторизует чисто по строкам грантов |
| `permission_drivers.gate` | — | `Vaulter\Access\Drivers\GatePermissionDriver` | драйвер Laravel Gate |
| `permission_drivers.spatie` | — | `Vaulter\Access\Drivers\SpatiePermissionDriver` | драйвер spatie/laravel-permission |
| `permission_drivers.azgard` | — | `Vaulter\Azgard\AzgardPermissionDriver` | регистрируется provider установленного `vaulter-azgard` |
| `permission_drivers.null` | — | `Vaulter\Testing\NullPermissionDriver` | открытый, **только не для продакшена** |

::: danger Null-драйвер в продакшене
Драйвер `null` открыт и завязан на окружение. Валидация продакшен-конфига отвергает панель, привязанную к нему, — привяжите реальный драйвер до деплоя.
:::

### Карта драйвера Gate — `gate.*`

Используется только когда `drivers.permission = 'gate'`. Маппит `GrantLevel` на право (ability) хостового Gate; наивысший разрешённый уровень становится ролевым грантом актора.

| Ключ | Env | По умолчанию |
|---|---|---|
| `gate.abilities.reader` | — | `vaulter.view` |
| `gate.abilities.commenter` | — | `vaulter.comment` |
| `gate.abilities.writer` | — | `vaulter.edit` |
| `gate.abilities.organizer` | — | `vaulter.organize` |
| `gate.abilities.owner` | — | `vaulter.manage` |
| `gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Карта драйвера Spatie — `spatie.*`

Используется только когда `drivers.permission = 'spatie'` **и** установлен spatie/laravel-permission.

| Ключ | Env | По умолчанию |
|---|---|---|
| `spatie.permissions.reader` | — | `vaulter.view` |
| `spatie.permissions.commenter` | — | `vaulter.comment` |
| `spatie.permissions.writer` | — | `vaulter.edit` |
| `spatie.permissions.organizer` | — | `vaulter.organize` |
| `spatie.permissions.owner` | — | `vaulter.manage` |
| `spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `spatie.role_subject_type` | — | `role` |
| `spatie.team_subject_type` | — | `team` |

`spatie.teams` используется как запасной вариант только тогда, когда собственный `config('permission.teams')` хоста не задан. `role_subject_type`/`team_subject_type` — это morph-алиасы, на которые адресуются строки грантов роли/команды.

### Карта драйвера AzGuard — `vaulter-azgard.*`

Отдельный пакет: `composer require axioma-studio/vaulter-azgard`. Настройки ниже
читаются из `config/vaulter-azgard.php`, не из конфига ядра.

| Ключ | По умолчанию |
|---|---|
| `vaulter-azgard.panel_id` | `null` (панель определяется из ключа разрешения) |

Используется только когда `drivers.permission = 'azgard'`. Ориентирована на действия — маппит право (ability) Vaulter на ключ разрешения AzGuard.

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

## Адаптер хранилища (легаси) — `storage_adapter`

| Ключ | По умолчанию |
|---|---|
| `storage_adapter` | `Vaulter\Support\DefaultStorageAdapter` |

Хранилище для легаси-вложений в бинарном виде — см. [`StorageAdapter`](/ru/reference/contracts#cas-content-addressed-storage).

## Контент-адресуемое хранилище блобов — `cas.*`

Сменяемые CAS-сервисы, все привязаны в провайдере и резолвятся через контейнер.

| Ключ | По умолчанию | Контракт |
|---|---|---|
| `cas.content_store` | `Vaulter\Media\Cas\Sha256ContentStore` | `ContentStore` |
| `cas.storage_driver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | `StorageDriver` |
| `cas.blob_store` | `Vaulter\Media\Cas\BlobStore` | `BlobStore` |

## Дедупликация — `dedup.scope`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `dedup.scope` | `VAULTER_DEDUP_SCOPE` | `tenant` | `global` \| `tenant` \| `storage` |

Граница, внутри которой контент-адрес (sha256) уникален. Определяет и UNIQUE-индекс дедупликации, и квалифицированный по области ключ хранилища `"<scope>/ab/cd/<sha256>"`. `global` = один физический объект на sha256 в пределах всей установки; `tenant` = уникальность на `(tenant_id, sha256)`; `storage` = уникальность на `(storage_id, sha256)`.

## Сборка мусора блобов — `gc.grace_hours`

| Ключ | Env | По умолчанию | Примечания |
|---|---|---|---|
| `gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | Grace-окно двухфазной сборки мусора; нижняя граница 1ч |

Блоб, у которого кеш счётчика ссылок достигает нуля, помечается tombstone с `delete_after_at = now + grace`; его байты выметаются только после того, как grace-окно прошло. См. [Рецепты → Сборка мусора блобов](/ru/guide/recipes).

## CAS-диск хранилища — `storage.default_disk`

| Ключ | Env | По умолчанию | Примечания |
|---|---|---|---|
| `storage.default_disk` | `VAULTER_DISK` | `local` | Flysystem-диск по умолчанию для канонических CAS-объектов, когда `v_storages.disk` блоба не закрепляет собственный |

::: warning Держите CAS-бакет без версионирования
Никогда не используйте S3 lifecycle/versioning как механизм удаления блобов.
:::

## Публичные ссылки (share)

### Легаси-генератор токенов — `share_token_generator`

| Ключ | По умолчанию |
|---|---|
| `share_token_generator` | `Vaulter\Support\RandomShareTokenGenerator` |

### Bearer-ссылки на узлы (share) — `share.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `share.token_generator` | — | `Vaulter\Support\RandomShareTokenGenerator` | `ShareTokenGenerator`, чеканящий сырой bearer-токен (хранится только его sha256-хеш) |
| `share.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | Публичная база, из которой собирается URL публичной ссылки |
| `share.prefix_length` | — | `8` | Сколько ведущих символов сырого токена хранится как несекретный `token_prefix` |

См. [Использование → Публичные ссылки](/ru/guide/sharing).

## Корзина — `trash.retention_days`

| Ключ | Env | По умолчанию |
|---|---|---|
| `trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

Окно хранения, по истечении которого поддерево узла из корзины окончательно принудительно удаляется командой `php artisan vaulter:purge-trash`.

## Резюмируемые загрузки — `uploads.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `uploads.max_bytes` | `VAULTER_UPLOAD_MAX_BYTES` | `5368709120` (5 ГиБ) | Жёсткий потолок на заявленный общий размер сессии; `0` = ∞ |
| `uploads.chunk_size` | `VAULTER_UPLOAD_CHUNK_SIZE` | `8388608` (8 МиБ) | Анонсируемый размер чанка по умолчанию (подсказка) |
| `uploads.session_ttl_hours` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` | `24` | Сессия, не закоммиченная в этом окне, выметается командой `vaulter:sweep-uploads` |
| `uploads.tus.enabled` | `VAULTER_UPLOAD_TUS_ENABLED` | `true` | Включает резюмируемые tus-эндпойнты (независимо от флага схемы `uploads`) |

Флаг `features.uploads` включает и схему, и группу маршрутов. См. [Использование → Загрузки](/ru/guide/uploads).

## Надёжность — вебхуки — `webhooks.*`

Транзакционный outbox + подписанная доставка вебхуков (at-least-once, экспоненциальный backoff, DLQ).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `webhooks.timeout` | — | `5` | Таймаут HTTP-запроса (секунды) на каждый POST доставки |
| `webhooks.max_attempts` | — | `5` | Всего попыток, прежде чем доставка паркуется как мёртвая (DLQ) |
| `webhooks.backoff` | — | `[10, 30, 60, 300, 1800]` | Задержка повторной попытки (секунды) на каждую попытку; хвост ограничивает дальнейшие ретраи |
| `webhooks.disable_after_failures` | — | `10` | Сколько подряд мёртвых доставок выключают `is_active` у эндпойнта |
| `webhooks.signature_header` | — | `X-Vaulter-Signature` | Заголовок подписи HMAC-SHA256 |
| `webhooks.timestamp_header` | — | `X-Vaulter-Timestamp` | Заголовок метки времени (защита от повторов) |
| `webhooks.event_header` | — | `X-Vaulter-Event` | Заголовок имени события |
| `webhooks.retention_days` | — | `30` | Возраст, после которого отправленные строки outbox + терминальные доставки можно вычищать |
| `webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | Право (ability) Gate, охраняющее поверхность управления эндпойнтами; `null` разрешает любому аутентифицированному пользователю |

Флаг `features.reliability` включает схему, подписчик outbox и маршруты эндпойнтов. См. [Рецепты → Надёжность и вебхуки](/ru/guide/recipes).

## API-маршруты — `route_prefix`, `middleware`

| Ключ | Env | По умолчанию |
|---|---|---|
| `route_prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |
| `middleware` | — | `['api']` |

## Версионирование (легаси) — `max_versions`

| Ключ | По умолчанию | Примечания |
|---|---|---|
| `max_versions` | `50` | Максимум хранимых версий на **легаси**-документ (`0` = без ограничений); самые старые обрезаются |

## Версии документов-узлов — `versions.*`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `versions.max` | `50` | Хранимых версий на узел до обрезки (`0` = без ограничений); откатывается к `max_versions` |
| `versions.autosave_debounce_seconds` | `30` | Автосохранение в пределах этого окна от головной автосейв-версии обновляет её на месте (без новой строки) |

См. [Использование → Версии](/ru/guide/documents).

## Документы — `enabled_document_types`, `max_document_size_kb`

| Ключ | По умолчанию |
|---|---|
| `enabled_document_types` | `['richtext', 'table', 'markdown', 'template']` |
| `max_document_size_kb` | `5120` |

## Drive-загрузки (бинарные) — `drive.*`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `drive.max_upload_kb` | `null` | Жёсткий потолок размера (КБ) для загружаемого файла, проверяется `UploadFileRequest`. `null` → откатывается к `max_document_size_kb` |
| `drive.allowed_mime` | `[]` | Опциональный allowlist mime. Пусто = разрешено всё (любой mime). Перечисление mime (например, `application/pdf`, `image/*`) ограничивает загрузки через правило `mimetypes` Laravel |

## Форматы экспорта — `exporters`

Карта ключ формата → FQCN `DocumentExporter`.

| Ключ | По умолчанию |
|---|---|
| `exporters.html` | `Vaulter\Export\HtmlExporter` |
| `exporters.markdown` | `Vaulter\Export\MarkdownExporter` |

Добавьте свой (PDF, DOCX, …) здесь или через `Vaulter::registerExporter()`. См. [Рецепты → Свой экспорт](/ru/guide/recipes).

## Совместное редактирование — `collaboration.*`

Yjs — источник истины; TipTap JSON — производная проекция. Транспорт сменяем за драйвером.

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | `hocuspocus` |
| `collaboration.channel_prefix` | — | `vaulter.documents` | Пространство имён комнаты; комната всегда `prefix.nodeId` |
| `collaboration.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | Общий секрет, который Node-хаб предъявляет на S2S webhook-эндпойнтах. Когда null/пусто, webhook-поверхность принимает только валидный подписанный JWT |
| `collaboration.drivers.hocuspocus.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | WebSocket-URL хаба |
| `collaboration.drivers.hocuspocus.app_id` | `VAULTER_COLLAB_APP_ID` | `null` | App id хаба |
| `collaboration.drivers.hocuspocus.secret` | `VAULTER_COLLAB_SECRET` | `null` | Ключ подписи room-JWT **и** ключ, по которому вебхуки проверяют JWT |
| `collaboration.drivers.hocuspocus.token_ttl` | — | `3600` | TTL room-JWT (секунды) |

См. [Совместное редактирование → Обзор](/ru/guide/collaboration) и [Совместное редактирование → Сервер](/ru/guide/collaboration).

## См. также

- [Фасад и Manager](/ru/reference/facade) — точки входа в рантайме
- [Справочник контрактов](/ru/reference/contracts) — интерфейсы, которые реализуют эти FQCN
- [Справочник команд](/ru/reference/commands) — планируемые команды обслуживания
