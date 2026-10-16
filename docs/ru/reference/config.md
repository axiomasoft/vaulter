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
| `models.blob` | `Vaulter\Media\Models\Cas\Blob` | core |
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

## Первичные ключи — `ids.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `ids.strategy` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | FQCN, реализующий `IdStrategy` |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | FQCN, реализующий `IdStrategy` |

Выбирайте `ids.strategy` напрямую.

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

Список FQCN `FileHandler`, которые принадлежащий core `Vaulter\Handlers\FileHandlerRegistry` резолвит по цепочке mime → расширение → возможность → приоритет, с откатом к null-объекту `DownloadOnlyHandler`. Две базовые записи — СОБСТВЕННЫЙ дефолт core (VLT-CANON P0.4/D10 — резолвятся даже без установленного `vaulter-documents`). Обработчики обслуживают только файлы: узлы-документы представляются своим [типом документа](/ru/extending/document-types).

| Встроенные обработчики через тег | Владелец |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |

Добавьте новый формат файлов одним классом + записью здесь (или через `Vaulter::handlers()->register()`).

## Тенантность — `tenancy.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | Интеграция тенантности хоста; колонка всегда `tenant_id`. Изоляцию задаёт модель хоста |

См. [Auth → Мультитенантность](/ru/concepts/tenancy).

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
и согласовать ULID-morph — см. [Драйверы прав](/ru/extending/permission-drivers).

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

Блоб, у которого кеш счётчика ссылок достигает нуля, помечается tombstone с `delete_after_at = now + grace`; его байты выметаются только после того, как grace-окно прошло. См. [Рецепты → Сборка мусора блобов](/ru/guides/recipes).

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

См. [Использование → Публичные ссылки](/ru/guides/sharing).

## Корзина — `vaulter-drive.trash.retention_days`

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

Окно хранения, по истечении которого поддерево узла из корзины окончательно принудительно удаляется командой `php artisan vaulter:trash:purge`.

## Диски — `vaulter.drives.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | `HasDrives`: удаление модели-владельца удаляет её диски (system-актор `owner-deleted`); при `SoftDeletes` считается только `forceDeleted` |

См. [Drive · жизненный цикл](/ru/concepts/nodes-and-revisions).

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

Транзакционный outbox + доставка, подписанная по [Standard Webhooks](/guides/webhooks#headers-and-signature) (at-least-once, расписание повторов, DLQ).

**Удалено в 1.0** — опубликованный конфиг с любым из этих ключей не загрузится: `InvalidConfigurationException` назовёт замену. `webhooks.max_attempts` и `webhooks.backoff` (→ `retry_schedule`), `webhooks.disable_after_failures` (→ `disable_after_days`), `webhooks.signature_header`, `webhooks.timestamp_header`, `webhooks.event_header` (заголовки зафиксированы стандартом).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `webhooks.timeout` | — | `15` | Таймаут HTTP-запроса (секунды) на каждый POST доставки |
| `webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Пауза (секунды) после n-й неудачной попытки; попыток = элементов + 1 (около трёх суток), последняя неудача делает доставку мёртвой |
| `webhooks.retry_jitter` | — | `0.1` | Доля (0–0,5), на которую случайно меняется каждая пауза повтора |
| `webhooks.disable_after_days` | — | `5` | Сколько суток непрерывных сбоев выключают эндпоинт (`0` — никогда) |
| `webhooks.secret_rotation_overlap` | — | `86400` | Сколько секунд прежний секрет продолжает подписывать после ротации (не более 604800) |
| `webhooks.retention_days` | — | `30` | Возраст, после которого терминальные доставки можно вычищать (`pending`/`failed` — никогда) |
| `webhooks.relay.fanout_chunk` | — | `100` | Сколько эндпоинтов пишется за одну транзакцию раскрытия (целое 1..1000; иное значение валит загрузку) |
| `webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | Право (ability) Gate, охраняющее поверхность управления эндпойнтами; `null` разрешает любому аутентифицированному пользователю |

Opt-in `vaulter.modules.webhooks` включает consumer outbox `webhooks.fanout` и маршруты эндпойнтов; таблицы существуют всегда. См. [Рецепты → Надёжность и вебхуки](/ru/guides/recipes).

## Outbox — `outbox.*`

Транзакционный outbox, в который пишется каждое событие, и его relay (`vaulter:relay`); см.
[Outbox](/ru/concepts/events-outbox). Каждое значение — положительное целое; иное значение валит загрузку.

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `outbox.settle_window_seconds` | — | `300` | Сверка доверяет только событиям старше этого; держите больше самой долгой транзакции Vaulter (`vaulter:doctor` сравнивает с лимитами базы) |
| `outbox.retention_seconds` | — | `604800` | Возраст, после которого удаляется событие без открытых доставок, которое не держит ни один consumer |
| `outbox.max_consumer_hold_seconds` | — | `604800` | Сколько приостановленный durable consumer держит retention до отсоединения с разрывом (не меньше окна сверки) |
| `outbox.dead_retention_seconds` | — | `2592000` | Сколько хранится мёртвая доставка для разбора и повтора |
| `outbox.lease_seconds` | — | `60` | Сколько relay-воркер владеет арендованной доставкой (а reconciler — consumer'ом) до перехвата |
| `outbox.batch` | — | `100` | Сколько доставок один проход relay берёт на consumer |
| `outbox.max_attempts` | — | `10` | После скольких попыток повторяемая доставка становится мёртвой |
| `outbox.backoff_base_seconds` | — | `2` | База задержки повтора full jitter |
| `outbox.backoff_cap_seconds` | — | `3600` | Потолок задержки повтора full jitter (не меньше базы) |

## Журнал изменений — `changes.*`

[Журнал изменений](/concepts/events) каждого drive, который синхронизирующие клиенты читают через `DriveHandle::changes()`. `features.changes` (по умолчанию `true`) включает запись; таблицы существуют всегда. Выключение и повторное включение оставляет пропуск, который закрывает только сброс (`vaulter:changes:reset`).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `changes.retention_days` | — | `30` | Сколько дней запись доступна для чтения; `vaulter:changes:prune` удаляет более старые, а курсор позади них истекает (`410 cursor_expired`). Целое ≥ 1, иное значение валит загрузку |
| `changes.max_page_size` | — | `1000` | Наибольший `limit` одной страницы `changes()->list()` (целое 1..10000, иное значение валит загрузку) |

## Планировщик — `schedule.*`

Какие команды обслуживания пакеты ставят в планировщик Laravel и как часто; список команд и
грамматика значений — в [Команды → Планировщик](/ru/reference/commands#планировщик).

| Ключ | Env | По умолчанию | Что задаёт |
|---|---|---|---|
| `schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | Общий выключатель всех плановых задач Vaulter (core, drive, media); поставьте `false`, если хост планирует команды сам. Только boolean |
| `schedule.outbox_relay` | — | `'everyMinute'` | `vaulter:relay`; `null` выключает |
| `schedule.outbox_prune` | — | `'hourly'` | `vaulter:outbox:prune`; `null` выключает |
| `schedule.webhooks_retry` | — | `'everyMinute'` | `vaulter:webhooks:retry`; `null` выключает |
| `schedule.webhooks_prune` | — | `'dailyAt:03:10'` | `vaulter:webhooks:prune`; `null` выключает |
| `schedule.changes_prune` | — | `'dailyAt:03:20'` | `vaulter:changes:prune`; `null` выключает |
| `vaulter-drive.schedule.*` | — | `uploads_sweep` `hourlyAt:15`, `trash_purge` `dailyAt:03:30`, `drives_purge` `hourlyAt:25`, `revisions_prune` `dailyAt:03:40`, `scan_reconcile` `everyFiveMinutes`, `drives_recount` `null` | Задачи drive; `scan_reconcile` работает, только пока режим scan не `off`, и не зависит от `features.webhooks` |
| `vaulter-media.schedule.*` | — | `blobs_gc` `dailyAt:04:00`, `blobs_reconcile` `weeklyOn:0,04:30` | Задачи блобов |

Неверное значение останавливает boot с `InvalidConfigurationException`; ключ, пропущенный в
массиве `schedule` хоста, получает значение пакета по умолчанию — задайте `null`, чтобы выключить задачу.

## API-маршруты — `routes.*`

| Ключ | Env | По умолчанию |
|---|---|---|
| `routes.prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |

## Версии документов-узлов — `vaulter-documents.revisions.*`

| Ключ | По умолчанию | Управляет |
|---|---|---|
| `vaulter-documents.revisions.limit` | `50` | Хранимых чекпоинтов на документ до обрезки (`0` = без ограничений) |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | `300` | Сколько секунд рабочее состояние может опережать последний автосохранённый чекпоинт (`0` — чекпоинт на каждое эффективное сохранение). |

См. [Использование → Версии](/ru/guides/documents).

## Документы — `vaulter-documents.types`, `default_type`, `content.max_size_kb`

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.types` | `[RichTextDocumentType::class, MarkdownDocumentType::class]` — зарегистрированные [типы документов](/ru/extending/document-types); добавьте `SpreadsheetDocumentType::class` или свой |
| `vaulter-documents.default_type` | `'vaulter/rich-text'` — тип, который использует `create()`, если тип не указан; обязан быть зарегистрирован |
| `vaulter-documents.content.max_size_kb` | `5120` — лимит кодека, у которого `maxSizeBytes()` равен `null` |

Без типа rich text (`['markdown']`) тип `vaulter/rich-text` не зарегистрирован, и приложение не загрузится,
пока `vaulter-documents.default_type` не назовёт зарегистрированный тип (`vaulter/markdown`).

## Форматы экспорта — `vaulter-documents.exporters`

Список имён классов `DocumentExporter`, разрешаемых по паре (тип документа, формат). Два
экспортёра на одну пару падают при загрузке; набор замораживается после загрузки.

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.exporters` | `[HtmlExporter::class, MarkdownExporter::class]` (`Vaulter\Documents\Export\*`) |

Добавьте свой (PDF, DOCX, …) здесь или через `Documents::exporters()->register()`. См. [Рецепты → Свой экспорт](/ru/guides/recipes).

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

См. [Совместное редактирование → Обзор](/ru/guides/collaboration) и [Совместное редактирование → Сервер](/ru/guides/collaboration).

## См. также

- [Фасад и Manager](/ru/reference/php-api) — точки входа в рантайме
- [Справочник контрактов](/ru/reference/php-api) — интерфейсы, которые реализуют эти FQCN
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
Комментарии включены по умолчанию; отключить: `vaulter-documents.comments.enabled = false`.

## Все ключи (генерируется)

<!-- vaulter:config-reference:start -->
### `vaulter-azgard`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-azgard.panel_id` | `VAULTER_AZGARD_PANEL` | `null` | AzGuard panel The AzGuard v1 panel the adapter passes as `guard:` to every check. null (the default) lets AzGuard select the panel — by a full name (`app:documents.view`) or a panel prefix in the permission, else the panel of the request, else the panel of the user model. The default map below names local keys (`documents.view`) of that panel, so the panel's resource prefix must not be `documents` (AzGuard refuses such a panel with PrefixConflictException): give the panel another resourcePrefix() or map the abilities below to other permissions. Set an explicit panel id when the user model belongs to several panels or the checks must not depend on the request panel; AzGuard throws when no panel can be selected. |
| `vaulter-azgard.guard` | — | `null` | Explicit guard A class implementing `Vaulter\Azgard\Contracts\AzgardGuard` that answers for EVERY actor (it is asked about the actor of each decision, not about the request user). null (the default) uses the actor itself when it is a guard, else an adapter over the actor when it is AzGuard-backed, else the lane passes through and grants nothing. |
| `vaulter-azgard.permissions.view` | — | `'documents.view'` | — |
| `vaulter-azgard.permissions.download` | — | `'documents.download'` | — |
| `vaulter-azgard.permissions.comment` | — | `'documents.comment'` | — |
| `vaulter-azgard.permissions.edit` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.create` | — | `'documents.create'` | — |
| `vaulter-azgard.permissions.move` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.copy` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.trash` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.delete` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.restore` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.share` | — | `'documents.manage'` | — |
| `vaulter-azgard.permissions.manage` | — | `'documents.manage'` | — |
| `vaulter-azgard.create_permission` | `VAULTER_AZGARD_CREATE_PERMISSION` | `'documents.create'` | — |

### `vaulter`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter.database.connection` | `VAULTER_DB_CONNECTION` | `null` | — |
| `vaulter.database.table_prefix` | `VAULTER_TABLE_PREFIX` | `'v_'` | — |
| `vaulter.database.write_guard` | `VAULTER_DB_WRITE_GUARD` | `true` | Refuse writes to Vaulter models outside the Vaulter operations (defence in depth, not a sandbox). |
| `vaulter.ids.strategy` | `VAULTER_ID_STRATEGY` | `'ulid'` | — |
| `vaulter.ids.host_keys` | `VAULTER_HOST_KEYS` | `'string'` | — |
| `vaulter.ids.strategies.bigint` | — | `AutoIncrementIds::class` | — |
| `vaulter.ids.strategies.ulid` | — | `UlidIds::class` | — |
| `vaulter.ids.strategies.uuid7` | — | `Uuid7Ids::class` | — |
| `vaulter.tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | — |
| `vaulter.tenancy.resolver` | — | `null` | — |
| `vaulter.profiles.default` | — | `DefaultProfile::class` | — |
| `vaulter.profiles.providers` | — | `[]` | — |
| `vaulter.scan.mode` | — | `null` | — |
| `vaulter.scan.required` | — | `null` | — |
| `vaulter.scan.scanner` | — | `null` | — |
| `vaulter.scan.scanner_key` | — | `null` | — |
| `vaulter.scan.scanner_version` | — | `null` | — |
| `vaulter.scan.policy_version` | — | `null` | — |
| `vaulter.scan.rollout_key` | — | `null` | — |
| `vaulter.modules` | — | `[]` | Module activation overrides (D22): module id => true\|false. Auto modules (media, drive, documents…) are active unless switched off; opt-in modules (webhooks) only when switched on. |
| `vaulter.features.grants` | — | `true` | — |
| `vaulter.features.activity` | — | `true` | — |
| `vaulter.features.changes` | — | `true` | The drive change journal behind DriveHandle::changes() (PLAN2.D50). |
| `vaulter.features.share_links` | — | `false` | — |
| `vaulter.features.public_listing` | — | `false` | — |
| `vaulter.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | HasDrives: delete the drives of an owner when the owner is deleted. |
| `vaulter.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `'database'` | — |
| `vaulter.access.permission_drivers.database` | — | `DatabasePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.gate` | — | `GatePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.spatie` | — | `SpatiePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.null` | — | `NullPermissionDriver::class` | — |
| `vaulter.access.gate.abilities.previewer` | — | `'vaulter.preview'` | — |
| `vaulter.access.gate.abilities.reader` | — | `'vaulter.view'` | — |
| `vaulter.access.gate.abilities.commenter` | — | `'vaulter.comment'` | — |
| `vaulter.access.gate.abilities.writer` | — | `'vaulter.edit'` | — |
| `vaulter.access.gate.abilities.organizer` | — | `'vaulter.organize'` | — |
| `vaulter.access.gate.abilities.owner` | — | `'vaulter.manage'` | — |
| `vaulter.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `'vaulter.super'` | — |
| `vaulter.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `'vaulter.create'` | — |
| `vaulter.access.spatie.permissions.previewer` | — | `'vaulter.preview'` | — |
| `vaulter.access.spatie.permissions.reader` | — | `'vaulter.view'` | — |
| `vaulter.access.spatie.permissions.commenter` | — | `'vaulter.comment'` | — |
| `vaulter.access.spatie.permissions.writer` | — | `'vaulter.edit'` | — |
| `vaulter.access.spatie.permissions.organizer` | — | `'vaulter.organize'` | — |
| `vaulter.access.spatie.permissions.owner` | — | `'vaulter.manage'` | — |
| `vaulter.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `'vaulter.super'` | — |
| `vaulter.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `'vaulter.create'` | — |
| `vaulter.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` | — |
| `vaulter.access.spatie.role_subject_type` | — | `'role'` | — |
| `vaulter.access.spatie.team_subject_type` | — | `'team'` | — |
| `vaulter.routes.prefix` | `VAULTER_ROUTE_PREFIX` | `'api/vaulter'` | — |
| `vaulter.routes.middleware.authenticated` | — | `['api', 'auth:sanctum']` | — |
| `vaulter.routes.middleware.public` | — | `['api']` | — |
| `vaulter.routes.middleware.hub` | — | `['api']` | — |
| `vaulter.routes.rate_limits.public` | `VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE` | `60` | — |
| `vaulter.routes.rate_limits.uploads` | `VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE` | `120` | — |
| `vaulter.listing.scan_budget` | — | `1000` | — |
| `vaulter.sharing.token_generator` | — | `RandomShareTokenGenerator::class` | — |
| `vaulter.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `'/share'` | — |
| `vaulter.sharing.prefix_length` | — | `8` | — |
| `vaulter.sharing.default_ttl_hours` | `VAULTER_SHARE_DEFAULT_TTL_HOURS` | `72` | — |
| `vaulter.sharing.max_ttl_hours` | `VAULTER_SHARE_MAX_TTL_HOURS` | `720` | — |
| `vaulter.downloads.delivery_mode` | — | `'strict'` | — |
| `vaulter.downloads.direct_ttl_seconds` | — | `60` | — |
| `vaulter.downloads.inline_mime_types` | — | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` | — |
| `vaulter.webhooks.timeout` | — | `15` | — |
| `vaulter.webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days in total). Jitter is the +/- fraction applied to each delay. |
| `vaulter.webhooks.retry_jitter` | — | `0.1` | — |
| `vaulter.webhooks.disable_after_days` | — | `5` | Days of uninterrupted failure before an endpoint is disabled; 0 never disables. |
| `vaulter.webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (max 604800). |
| `vaulter.webhooks.retention_days` | — | `30` | — |
| `vaulter.webhooks.retry_claim_seconds` | — | `60` | — |
| `vaulter.webhooks.relay` | — | — | The webhooks.fanout outbox consumer materializes deliveries fanout_chunk endpoints at a time (1..1000), each chunk in its own transaction under the outbox delivery lease. |
| `vaulter.webhooks.relay.fanout_chunk` | — | `100` | — |
| `vaulter.webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `'vaulter.webhooks.manage'` | — |
| `vaulter.webhooks.delivery.url_guard` | — | `DefaultOutboundUrlGuard::class` | — |
| `vaulter.webhooks.delivery.allowed_schemes` | — | `['http', 'https']` | — |
| `vaulter.webhooks.delivery.blocked_ip_ranges` | — | `null` | — |
| `vaulter.webhooks.delivery.allowed_ip_ranges` | — | `[]` | — |
| `vaulter.webhooks.delivery.connect_timeout` | — | `2` | — |
| `vaulter.webhooks.delivery.max_response_bytes` | — | `65536` | — |
| `vaulter.changes.retention_days` | — | `30` | Days a journal entry stays readable; vaulter:changes:prune deletes older ones and a cursor behind them expires (410 cursor_expired). |
| `vaulter.changes.max_page_size` | — | `1000` | The largest page DriveHandle::changes()->list() returns. |
| `vaulter.outbox` | — | — | The transactional outbox (D29): every domain event is written to v_outbox in the transaction of its operation; vaulter:relay hands it to each outbox consumer (webhooks.fanout, …) at least once. settle_window_seconds must exceed the longest Vaulter transaction: reconciliation only trusts events older than it, so a long transaction committing a smaller seq later is not skipped. |
| `vaulter.outbox.settle_window_seconds` | — | `300` | — |
| `vaulter.outbox.retention_seconds` | — | `604800` | — |
| `vaulter.outbox.max_consumer_hold_seconds` | — | `604800` | How long a paused consumer (switched off, missing from the module graph) holds retention before it is detached with an explicit gap. |
| `vaulter.outbox.dead_retention_seconds` | — | `2592000` | — |
| `vaulter.outbox.lease_seconds` | — | `60` | — |
| `vaulter.outbox.batch` | — | `100` | — |
| `vaulter.outbox.max_attempts` | — | `10` | — |
| `vaulter.outbox.backoff_base_seconds` | — | `2` | — |
| `vaulter.outbox.backoff_cap_seconds` | — | `3600` | — |
| `vaulter.idempotency` | — | — | Idempotency-Key receipts (D25): how long a retry with the same key gets the stored outcome. vaulter:idempotency:prune deletes expired receipts. |
| `vaulter.idempotency.ttl_seconds` | — | `86400` | — |
| `vaulter.schedule` | — | — | Maintenance tasks put on the host scheduler. Value: null (off) \| everyMinute \| everyFiveMinutes hourly \| daily \| weekly \| 'dailyAt:HH:MM' \| 'hourlyAt:M' \| 'weeklyOn:D,HH:MM' \| a 5-field cron expression. Set enabled to false when the host schedules the commands itself. |
| `vaulter.schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | — |
| `vaulter.schedule.outbox_relay` | — | `'everyMinute'` | — |
| `vaulter.schedule.outbox_prune` | — | `'hourly'` | — |
| `vaulter.schedule.webhooks_retry` | — | `'everyMinute'` | — |
| `vaulter.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | — |
| `vaulter.schedule.changes_prune` | — | `'dailyAt:03:20'` | — |
| `vaulter.schedule.idempotency_prune` | — | `'dailyAt:03:30'` | — |
| `vaulter.handlers` | — | `[]` | — |
| `vaulter.observability.enabled` | `VAULTER_OBSERVABILITY_ENABLED` | `false` | — |
| `vaulter.observability.recorder` | `VAULTER_OBSERVABILITY_RECORDER` | `null` | — |
| `vaulter.observability.tracing.enabled` | `VAULTER_TRACING_ENABLED` | `false` | — |
| `vaulter.observability.tracing.recorder` | `VAULTER_TRACING_RECORDER` | `null` | — |
| `vaulter.models.drive` | — | `Drive::class` | — |
| `vaulter.models.node` | — | `Node::class` | — |
| `vaulter.models.file` | — | `File::class` | — |
| `vaulter.models.scan_obligation` | — | `ScanObligation::class` | — |
| `vaulter.models.scan_request` | — | `ScanRequest::class` | — |
| `vaulter.models.scan_evidence` | — | `ScanEvidenceRecord::class` | — |
| `vaulter.models.scan_policy_state` | — | `ScanPolicyState::class` | — |
| `vaulter.models.scan_backfill_run` | — | `ScanBackfillRun::class` | — |
| `vaulter.models.file_revision` | — | `FileRevision::class` | — |
| `vaulter.models.file_revision_blob` | — | `FileRevisionBlob::class` | — |
| `vaulter.models.grant` | — | `NodeGrant::class` | — |
| `vaulter.models.node_share` | — | `NodeShare::class` | — |
| `vaulter.models.activity` | — | `Activity::class` | — |
| `vaulter.models.outbox` | — | `OutboxEvent::class` | — |
| `vaulter.models.webhook_endpoint` | — | `WebhookEndpoint::class` | — |
| `vaulter.models.webhook_delivery` | — | `WebhookDelivery::class` | — |

### `vaulter-documents`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-documents.types` | — | `[RichTextDocumentType::class, MarkdownDocumentType::class]` | Document type classes; add SpreadsheetDocumentType or your own here. |
| `vaulter-documents.default_type` | — | `'vaulter/rich-text'` | — |
| `vaulter-documents.content.max_size_kb` | — | `5120` | — |
| `vaulter-documents.revisions.limit` | — | `50` | — |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | — | `300` | — |
| `vaulter-documents.comments.enabled` | — | `true` | — |
| `vaulter-documents.exporters` | — | `[HtmlExporter::class, MarkdownExporter::class]` | Document exporter classes, resolved by (document type, format). |
| `vaulter-documents.tiptap.extra_nodes` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_marks` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_attributes` | — | `[]` | — |
| `vaulter-documents.tiptap.url_attributes` | — | `[]` | — |
| `vaulter-documents.collaboration.enabled` | — | `false` | — |
| `vaulter-documents.collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `'hocuspocus'` | — |
| `vaulter-documents.collaboration.hub.url` | `VAULTER_COLLAB_URL` | `'ws://localhost:1234'` | — |
| `vaulter-documents.collaboration.hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | `null` | — |
| `vaulter-documents.collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | — |
| `vaulter-documents.collaboration.hub.token_ttl` | — | `3600` | — |
| `vaulter-documents.collaboration.hub.timestamp_tolerance` | — | `300` | — |
| `vaulter-documents.collaboration.max_state_bytes` | — | `16777216` | The largest binary Yjs state one hub store may write (413 above it). |
| `vaulter-documents.collaboration.broadcast.channel_prefix` | — | `'vaulter.documents'` | — |
| `vaulter-documents.models.document_revision` | — | `DocumentRevision::class` | — |
| `vaulter-documents.models.node_comment` | — | `NodeComment::class` | — |

### `vaulter-drive`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-drive.uploads.scan.required` | — | `null` | — |
| `vaulter-drive.uploads.scan.mode` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner_key` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner_version` | — | `null` | — |
| `vaulter-drive.uploads.scan.policy_version` | — | `null` | — |
| `vaulter-drive.uploads.scan.rollout_key` | — | `null` | — |
| `vaulter-drive.uploads.scan.queue` | — | `'default'` | — |
| `vaulter-drive.uploads.scan.lease_seconds` | — | `120` | — |
| `vaulter-drive.uploads.scan.scanner_timeout_seconds` | — | `60` | — |
| `vaulter-drive.uploads.scan.max_attempts` | — | `5` | — |
| `vaulter-drive.uploads.scan.publish_cooldown_seconds` | — | `30` | — |
| `vaulter-drive.uploads.scan.recovery_batch` | — | `100` | — |
| `vaulter-drive.uploads.scan.cleanup_batch` | — | `100` | — |
| `vaulter-drive.uploads.max_file_size` | `VAULTER_UPLOAD_MAX_BYTES` | `5_368_709_120` | — |
| `vaulter-drive.uploads.allowed_mime_types` | — | `[]` | — |
| `vaulter-drive.uploads.resumable.enabled` | `VAULTER_UPLOAD_TUS_ENABLED` | `true` | — |
| `vaulter-drive.uploads.resumable.chunk_size` | `VAULTER_UPLOAD_CHUNK_SIZE` | `8_388_608` | — |
| `vaulter-drive.uploads.resumable.max_chunk_size` | `VAULTER_UPLOAD_MAX_CHUNK_BYTES` | `67_108_864` | — |
| `vaulter-drive.uploads.resumable.session_ttl_hours` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` | `24` | — |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` | `20` | — |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | — | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | — | `512` | — |
| `vaulter-drive.trash.retention_days` | — | `30` | — |
| `vaulter-drive.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-drive.schedule.uploads_sweep` | — | `'hourlyAt:15'` | — |
| `vaulter-drive.schedule.trash_purge` | — | `'dailyAt:03:30'` | — |
| `vaulter-drive.schedule.drives_purge` | — | `'hourlyAt:25'` | — |
| `vaulter-drive.schedule.revisions_prune` | — | `'dailyAt:03:40'` | — |
| `vaulter-drive.schedule.scan_reconcile` | — | `'everyFiveMinutes'` | — |
| `vaulter-drive.schedule.drives_recount` | — | `null` | Report-only drift check; off until an operator wants it. |
| `vaulter-drive.models.upload_session` | — | `UploadSession::class` | — |
| `vaulter-drive.models.upload_part` | — | `UploadPart::class` | — |

### `vaulter-filament`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-filament.navigation_group` | — | `'Vaulter'` | Navigation group The Filament navigation group the Vaulter resources are listed under. |
| `vaulter-filament.resources` | — | — | Enabled resources Toggle which Vaulter Filament resources the plugin registers — the Drive/permissions domain (drive, node, grant, share, activity) over the unified v_nodes tree. Each can be turned off independently; every entry defaults to true when omitted. |
| `vaulter-filament.resources.drive` | — | `true` | Drive / permissions domain (M1–M4 core). |
| `vaulter-filament.resources.node` | — | `true` | — |
| `vaulter-filament.resources.grant` | — | `true` | — |
| `vaulter-filament.resources.share` | — | `true` | — |
| `vaulter-filament.resources.activity` | — | `true` | — |

### `vaulter-media`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-media.disk` | `VAULTER_DISK` | `'local'` | — |
| `vaulter-media.dedup` | — | — | tenant (default) \| drive \| off — identical bytes are never shared across tenants. |
| `vaulter-media.dedup.scope` | `VAULTER_DEDUP_SCOPE` | `'tenant'` | — |
| `vaulter-media.gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | — |
| `vaulter-media.gc.orphan_grace_hours` | `VAULTER_ORPHAN_GRACE_HOURS` | `env('VAULTER_GC_GRACE_HOURS', 24)` | — |
| `vaulter-media.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-media.schedule.blobs_gc` | — | `'dailyAt:04:00'` | — |
| `vaulter-media.schedule.blobs_reconcile` | — | `'weeklyOn:0,04:30'` | — |
| `vaulter-media.content_store` | — | `Sha256ContentStore::class` | — |
| `vaulter-media.storage_driver` | — | `FlysystemStorageDriver::class` | — |
| `vaulter-media.blob_store` | — | `BlobStore::class` | — |
| `vaulter-media.models` | — | — | Swappable Eloquent model of the blob table (must extend the package model). |
| `vaulter-media.models.blob` | — | `Blob::class` | — |
<!-- vaulter:config-reference:end -->
