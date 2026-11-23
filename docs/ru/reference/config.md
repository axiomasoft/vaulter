---
title: Справочник по конфигурации
description: Исчерпывающий справочник по каждому ключу config/vaulter.php — значения по умолчанию, ENV-переменные и допустимые значения.
outline: deep
---

# Справочник по конфигурации

Ключи ядра и каждого установленного пакета, разбитые по секциям. У каждого пакета один корень конфига, и он
публикует свой файл под своим тегом: `vaulter` (`vaulter-config`, ядро), `vaulter-media`, `vaulter-drive`,
`vaulter-documents`, `vaulter-webhooks`, `vaulter-azguard` и `vaulter-filament` (`<корень>-config`). Ключ переехал
вместе со своим понятием: доступ, sharing, профили, фичи, обработчики и модели drive лежат в `vaulter-drive`,
настройки вебхуков — в `vaulter-webhooks`, модель блоба — в `vaulter-media`. Опубликовать всё сразу —
`php artisan vaulter:install`.

```bash
php artisan vendor:publish --tag=vaulter-config
```

Колонки каждой таблицы: путь до конфиг-ключа (с корнем), ENV-переопределение (если оно есть), поставляемое значение по умолчанию и то, чем ключ управляет. Значения читаются через собственные `Configuration\*Config` классы пакетов, внедряемые в сервисы вместо прямых вызовов `config()`.

::: warning Безопасно для кеша конфига
Каждая привязка — это FQCN или обычный скаляр, никаких замыканий. Конфиг безопасно прогонять через `php artisan config:cache`.
:::

## Модели — `models.*`

Сменяемые Eloquent-модели, каждая — в конфиге пакета-владельца. Направьте любую запись на собственный подкласс, чтобы добавить поведение (ULID-ключи, изоляция по тенанту, аудит). Vaulter всегда резолвит модели через эти карты.

| Ключ | По умолчанию | Владелец |
|---|---|---|
| `vaulter-drive.models.drive` | `Vaulter\Drive\Models\Drive` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.node` | `Vaulter\Drive\Models\Node` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.file` | `Vaulter\Drive\Models\File` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.file_revision` | `Vaulter\Drive\Models\FileRevision` | `axiomasoft/vaulter-drive` |
| `vaulter-media.models.blob` | `Vaulter\Media\Models\Cas\Blob` | `axiomasoft/vaulter-media` |
| `vaulter-drive.models.file_revision_blob` | `Vaulter\Drive\Models\FileRevisionBlob` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.grant` | `Vaulter\Drive\Models\Access\NodeGrant` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.node_share` | `Vaulter\Drive\Models\Sharing\NodeShare` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axiomasoft/vaulter-drive` |
| `vaulter-documents.models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axiomasoft/vaulter-documents` |
| `vaulter-documents.models.document_revision` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axiomasoft/vaulter-documents` |
| `vaulter-drive.models.activity` | `Vaulter\Drive\Models\Activity\Activity` | `axiomasoft/vaulter-drive` |
| `vaulter.models.outbox` | `Vaulter\Events\Outbox\Models\OutboxEvent` | core |
| `vaulter-webhooks.models.webhook_endpoint` | `Vaulter\Webhooks\Models\WebhookEndpoint` | `axiomasoft/vaulter-webhooks` |
| `vaulter-webhooks.models.webhook_delivery` | `Vaulter\Webhooks\Models\WebhookDelivery` | `axiomasoft/vaulter-webhooks` |

Модели настраиваются только под корнем владельца (`vaulter-drive.models.*`, `vaulter-media.models.*`, `vaulter-documents.models.*`, `vaulter-webhooks.models.*`); в карте ядра только модель outbox.

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

## Profiles — `vaulter-drive.profiles.*`

| Ключ | По умолчанию |
|---|---|
| `vaulter-drive.profiles.providers` | `[]` |
| `vaulter-drive.profiles.default` | `Vaulter\Drive\Profiles\DefaultProfile` |

Оба ключа принимают FQCN `ProfileProvider`. Ключи профилей уникальны; профиль по умолчанию зарегистрирован.

## Фичи — `vaulter-drive.features.*`

Глобальные переключатели фич, читаются через `DriveOptions::featureEnabled()`; панель может переопределить любую из них для себя.

| Ключ | По умолчанию | Отвечает за |
|---|---|---|
| `vaulter-drive.features.grants` | `true` | Гранты на узлы (ACL) |
| `vaulter-drive.features.activity` | `true` | Журнал активности |
| `vaulter-drive.features.share_links` | `false` | Bearer-ссылки на узлы (share) |
| `vaulter-drive.features.public_listing` | `false` | Публичный листинг |
| `vaulter-drive.features.changes` | `true` | Журнал изменений drive за `DriveHandle::changes()` |

## Обработчики документов — `vaulter-drive.handlers`

Список FQCN `FileHandler`, которые принадлежащий drive `Vaulter\Drive\Handlers\FileHandlerRegistry` резолвит по цепочке mime → расширение → возможность → приоритет, с откатом к null-объекту `DownloadOnlyHandler`. Две базовые записи — собственный дефолт drive (резолвятся даже без установленного `vaulter-documents`). Обработчики обслуживают только файлы: узлы-документы представляются своим [типом документа](/ru/extending/document-types).

| Встроенные обработчики через тег | Владелец |
|---|---|
| `Vaulter\Drive\Handlers\Builtin\PreviewHandler` | `axiomasoft/vaulter-drive` |
| `Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler` | `axiomasoft/vaulter-drive` |

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

### Drive ACL — `vaulter-drive.access.permission_driver`, `vaulter-drive.access.permission_drivers.*`

| Ключ | Env | По умолчанию | Принимает |
|---|---|---|---|
| `vaulter-drive.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `database` | ключ из `vaulter-drive.access.permission_drivers` |
| `vaulter-drive.access.permission_drivers.database` | — | `Vaulter\Drive\Access\Drivers\DatabasePermissionDriver` | автономный — авторизует чисто по строкам грантов |
| `vaulter-drive.access.permission_drivers.gate` | — | `Vaulter\Drive\Access\Drivers\GatePermissionDriver` | драйвер Laravel Gate |
| `vaulter-drive.access.permission_drivers.spatie` | — | `Vaulter\Drive\Access\Drivers\SpatiePermissionDriver` | драйвер spatie/laravel-permission |
| `vaulter-drive.access.permission_drivers.azguard` | — | `Vaulter\AzGuard\AzGuardPermissionDriver` | регистрируется provider установленного `vaulter-azguard` |
| `vaulter-drive.access.permission_drivers.null` | — | `Vaulter\Drive\Testing\NullPermissionDriver` | открытый, **только не для продакшена** |

::: danger Null-драйвер в продакшене
Драйвер `null` открыт и завязан на окружение. Валидация продакшен-конфига отвергает панель, привязанную к нему, — привяжите реальный драйвер до деплоя.
:::

### Карта драйвера Gate — `vaulter-drive.access.gate.*`

Используется только когда `vaulter-drive.access.permission_driver = 'gate'`. Маппит `GrantLevel` на право (ability) хостового Gate; наивысший разрешённый уровень становится ролевым грантом актора.

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-drive.access.gate.abilities.reader` | — | `vaulter.view` |
| `vaulter-drive.access.gate.abilities.commenter` | — | `vaulter.comment` |
| `vaulter-drive.access.gate.abilities.writer` | — | `vaulter.edit` |
| `vaulter-drive.access.gate.abilities.organizer` | — | `vaulter.organize` |
| `vaulter-drive.access.gate.abilities.owner` | — | `vaulter.manage` |
| `vaulter-drive.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `vaulter-drive.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Карта драйвера Spatie — `vaulter-drive.access.spatie.*`

Используется только когда `vaulter-drive.access.permission_driver = 'spatie'` **и** установлен spatie/laravel-permission.

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-drive.access.spatie.permissions.reader` | — | `vaulter.view` |
| `vaulter-drive.access.spatie.permissions.commenter` | — | `vaulter.comment` |
| `vaulter-drive.access.spatie.permissions.writer` | — | `vaulter.edit` |
| `vaulter-drive.access.spatie.permissions.organizer` | — | `vaulter.organize` |
| `vaulter-drive.access.spatie.permissions.owner` | — | `vaulter.manage` |
| `vaulter-drive.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `vaulter-drive.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `vaulter-drive.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `vaulter-drive.access.spatie.role_subject_type` | — | `role` |
| `vaulter-drive.access.spatie.team_subject_type` | — | `team` |

`vaulter-drive.access.spatie.teams` используется как запасной вариант только тогда, когда собственный `config('permission.teams')` хоста не задан. `role_subject_type`/`team_subject_type` — это morph-алиасы, на которые адресуются строки грантов роли/команды.

### Карта драйвера AzGuard — `vaulter-azguard.*`

Отдельный пакет: `composer require axiomasoft/vaulter-azguard`. Настройки ниже
читаются из `config/vaulter-azguard.php`, не из конфига ядра.

| Ключ | По умолчанию |
|---|---|
| `vaulter-azguard.panel_id` | `null` (панель определяется из ключа разрешения) |

Используется только когда `vaulter-drive.access.permission_driver = 'azguard'`. Ориентирована на действия — маппит право (ability) Vaulter на ключ разрешения AzGuard.

| Ключ | По умолчанию |
|---|---|
| `vaulter-azguard.permissions.view` | `documents.view` |
| `vaulter-azguard.permissions.download` | `documents.download` |
| `vaulter-azguard.permissions.comment` | `documents.comment` |
| `vaulter-azguard.permissions.edit` | `documents.update` |
| `vaulter-azguard.permissions.create` | `documents.create` |
| `vaulter-azguard.permissions.move` | `documents.update` |
| `vaulter-azguard.permissions.copy` | `documents.update` |
| `vaulter-azguard.permissions.trash` | `documents.delete` |
| `vaulter-azguard.permissions.delete` | `documents.delete` |
| `vaulter-azguard.permissions.restore` | `documents.delete` |
| `vaulter-azguard.permissions.share` | `documents.manage` |
| `vaulter-azguard.permissions.manage` | `documents.manage` |

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-azguard.create_permission` | `VAULTER_AZGUARD_CREATE_PERMISSION` | `documents.create` |

Мост поставляет реальный адаптер guard. Хост должен реализовать `AzGuardBackedUser`
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
| `vaulter-media.disk` | `VAULTER_DISK` | `local` | Flysystem-диск по умолчанию для канонических CAS-объектов, когда `v_drives.disk` блоба не закрепляет собственный |

::: warning Держите CAS-бакет без версионирования
Никогда не используйте S3 lifecycle/versioning как механизм удаления блобов.
:::

## Публичные ссылки (share)

### Bearer-ссылки на узлы (share) — `vaulter-drive.sharing.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter-drive.sharing.token_generator` | — | `Vaulter\Drive\Support\RandomShareTokenGenerator` | `ShareTokenGenerator`, чеканящий сырой bearer-токен (хранится только его sha256-хеш) |
| `vaulter-drive.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | Публичная база, из которой собирается URL публичной ссылки |
| `vaulter-drive.sharing.prefix_length` | — | `8` | Сколько ведущих символов сырого токена хранится как несекретный `token_prefix` |

См. [Использование → Публичные ссылки](/ru/guides/sharing).

## Корзина — `vaulter-drive.trash.retention_days`

| Ключ | Env | По умолчанию |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

Окно хранения, по истечении которого поддерево узла из корзины окончательно принудительно удаляется командой `php artisan vaulter:trash:purge`.

## Диски — `vaulter-drive.drives.*`

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter-drive.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | `HasDrives`: удаление модели-владельца удаляет её диски (system-актор `owner-deleted`); при `SoftDeletes` считается только `forceDeleted` |

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

## Надёжность — вебхуки — `vaulter-webhooks.*`

Отдельный пакет `axiomasoft/vaulter-webhooks` (opt-in модуль `webhooks`; файл конфига `config/vaulter-webhooks.php`,
тег `vaulter-webhooks-config`).

Транзакционный outbox + доставка, подписанная по [Standard Webhooks](/guides/webhooks#headers-and-signature) (at-least-once, расписание повторов, DLQ).

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter-webhooks.timeout` | — | `15` | Таймаут HTTP-запроса (секунды) на каждый POST доставки |
| `vaulter-webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Пауза (секунды) после n-й неудачной попытки; попыток = элементов + 1 (около трёх суток), последняя неудача делает доставку мёртвой |
| `vaulter-webhooks.retry_jitter` | — | `0.1` | Доля (0–0,5), на которую случайно меняется каждая пауза повтора |
| `vaulter-webhooks.disable_after_days` | — | `5` | Сколько суток непрерывных сбоев выключают эндпоинт (`0` — никогда) |
| `vaulter-webhooks.secret_rotation_overlap` | — | `86400` | Сколько секунд прежний секрет продолжает подписывать после ротации (не более 604800) |
| `vaulter-webhooks.retention_days` | — | `30` | Возраст, после которого терминальные доставки можно вычищать (`pending`/`failed` — никогда) |
| `vaulter-webhooks.relay.fanout_chunk` | — | `100` | Сколько эндпоинтов пишется за одну транзакцию раскрытия (целое 1..1000; иное значение валит загрузку) |
| `vaulter-webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | Право (ability) Gate, охраняющее поверхность управления эндпойнтами; `null` разрешает любому аутентифицированному пользователю |

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

## Журнал изменений — `vaulter-drive.changes.*`

[Журнал изменений](/concepts/events) каждого drive, который синхронизирующие клиенты читают через `DriveHandle::changes()`. `vaulter-drive.features.changes` (по умолчанию `true`) включает запись; таблицы существуют всегда. Выключение и повторное включение требует повышения `key_generation` владельцем на всех инстансах и полного bootstrap клиентов; старые курсоры через пропуск не переиспользуются.

| Ключ | Env | По умолчанию | Управляет |
|---|---|---|---|
| `vaulter-drive.changes.retention_days` | — | `30` | Сколько дней запись доступна для чтения; `vaulter:changes:prune` удаляет более старые, а курсор позади них истекает (`410 cursor_expired`). Целое ≥ 1, иное значение валит загрузку |
| `vaulter-drive.changes.max_page_size` | — | `1000` | Наибольший `limit` одной страницы `changes()->list()` (целое 1..10000, иное значение валит загрузку) |

## Планировщик — `schedule.*`

Какие команды обслуживания пакеты ставят в планировщик Laravel и как часто; список команд и
грамматика значений — в [Команды → Планировщик](/ru/reference/commands#планировщик).

| Ключ | Env | По умолчанию | Что задаёт |
|---|---|---|---|
| `schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | Общий выключатель всех плановых задач Vaulter (core, drive, media, webhooks); поставьте `false`, если хост планирует команды сам. Только boolean |
| `schedule.outbox_relay` | — | `'everyMinute'` | `vaulter:relay`; `null` выключает |
| `schedule.outbox_prune` | — | `'hourly'` | `vaulter:outbox:prune`; `null` выключает |
| `vaulter-webhooks.schedule.webhooks_retry` | — | `'everyMinute'` | `vaulter:webhooks:retry`; `null` выключает |
| `vaulter-webhooks.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | `vaulter:webhooks:prune`; `null` выключает |
| `vaulter-drive.schedule.changes_prune` | — | `'dailyAt:03:20'` | `vaulter:changes:prune`; `null` выключает |
| `vaulter-drive.schedule.*` | — | `uploads_sweep` `hourlyAt:15`, `trash_purge` `dailyAt:03:30`, `drives_purge` `hourlyAt:25`, `revisions_prune` `dailyAt:03:40`, `scan_reconcile` `everyFiveMinutes`, `drives_recount` `null` | Задачи drive; `scan_reconcile` работает, только пока режим scan не `off`, и не зависит от `vaulter.modules.webhooks` |
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
| `vaulter-documents.types` | `[RichTextDocumentType::class, MarkdownDocumentType::class]` — зарегистрированные [типы документов](/ru/extending/document-types); добавьте свой |
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

## Коллаборация {#collaboration}

Эксплуатация коммерческого дополнения описана в его приватном руководстве.

## См. также

- [Фасад](/ru/reference/php-api) — точки входа в рантайме
- [Справочник контрактов](/ru/reference/php-api) — интерфейсы, которые реализуют эти FQCN
- [Справочник команд](/ru/reference/commands) — планируемые команды обслуживания

## Дополнительные настройки

| Ключ | По умолчанию |
|---|---|
| `vaulter-documents.comments.enabled` | `true` |
| `vaulter-documents.tiptap.extra_nodes` | `[]` |
| `vaulter-documents.tiptap.extra_marks` | `[]` |
| `vaulter-documents.tiptap.extra_attributes` | `[]` |
| `vaulter-documents.tiptap.url_attributes` | `[]` |
| `vaulter-media.gc.orphan_grace_hours` | `24` (`VAULTER_ORPHAN_GRACE_HOURS`, откат к `VAULTER_GC_GRACE_HOURS`) |
| `ids.host_keys` | `string` (`VAULTER_HOST_KEYS`) |
| `vaulter-drive.listing.scan_budget` | `1000` |
| `routes.middleware.authenticated` | `['api', 'auth:sanctum']` |
| `routes.middleware.public` | `['api']` |
| `routes.rate_limits.public` | `60` (`VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE`) |
| `routes.rate_limits.uploads` | `120` (`VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE`) |
| `vaulter-drive.sharing.default_ttl_hours` | `72` (`VAULTER_SHARE_DEFAULT_TTL_HOURS`) |
| `vaulter-drive.sharing.max_ttl_hours` | `720` (`VAULTER_SHARE_MAX_TTL_HOURS`) |
| `vaulter-drive.downloads.inline_mime_types` | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` |
| `observability.enabled` | `false` (`VAULTER_OBSERVABILITY_ENABLED`) |
| `observability.recorder` | `null` (`VAULTER_OBSERVABILITY_RECORDER`) |
| `observability.tracing.enabled` | `false` (`VAULTER_TRACING_ENABLED`) |
| `observability.tracing.recorder` | `null` (`VAULTER_TRACING_RECORDER`) |
| `vaulter-webhooks.retry_claim_seconds` | `60` |
| `vaulter-webhooks.delivery.url_guard` | `Vaulter\Webhooks\DefaultOutboundUrlGuard` |
| `vaulter-webhooks.delivery.allowed_schemes` | `['http', 'https']` |
| `vaulter-webhooks.delivery.blocked_ip_ranges` | `null` (закрытые встроенные диапазоны) |
| `vaulter-webhooks.delivery.allowed_ip_ranges` | `[]` |
| `vaulter-webhooks.delivery.connect_timeout` | `2` секунд |
| `vaulter-webhooks.delivery.max_response_bytes` | `65536` байт |

Документы включены при установке пакета; блобы и ревизии файлов включены всегда.
Комментарии включены по умолчанию; отключить: `vaulter-documents.comments.enabled = false`.

## Все ключи (генерируется)

<!-- vaulter:config-reference:start -->
### `vaulter-azguard`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-azguard.panel_id` | `VAULTER_AZGUARD_PANEL` | `null` | AzGuard panel The AzGuard v1 panel the adapter passes as `guard:` to every check. null (the default) lets AzGuard select the panel — by a full name (`app:documents.view`) or a panel prefix in the permission, else the panel of the request, else the panel of the user model. The default map below names local keys (`documents.view`) of that panel, so the panel's resource prefix must not be `documents` (AzGuard refuses such a panel with PrefixConflictException): give the panel another resourcePrefix() or map the abilities below to other permissions. Set an explicit panel id when the user model belongs to several panels or the checks must not depend on the request panel; AzGuard throws when no panel can be selected. |
| `vaulter-azguard.guard` | — | `null` | Explicit guard A class implementing `Vaulter\AzGuard\Contracts\AzGuardGuard` that answers for the required `$actor` argument of each check. It must not substitute the request user or switch authentication state. null (the default) uses the actor itself when it is a guard, else an adapter over the actor when it is AzGuard-backed, else the lane passes through and grants nothing. |
| `vaulter-azguard.permissions` | — | — | A role is granted only when every ability of its registered version has a mapping and the actor holds every mapped permission. Missing mappings never imply permission. |
| `vaulter-azguard.permissions.view` | — | `'documents.view'` | — |
| `vaulter-azguard.permissions.download` | — | `'documents.download'` | — |
| `vaulter-azguard.permissions.comment` | — | `'documents.comment'` | — |
| `vaulter-azguard.permissions.edit` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.create` | — | `'documents.create'` | — |
| `vaulter-azguard.permissions.move` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.copy` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.trash` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.delete` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.restore` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.share` | — | `'documents.manage'` | — |
| `vaulter-azguard.permissions.manage` | — | `'documents.manage'` | — |
| `vaulter-azguard.create_permission` | `VAULTER_AZGUARD_CREATE_PERMISSION` | `'documents.create'` | — |

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
| `vaulter.security` | — | — | id => nonempty reason: production invariants a host knowingly accepts (ADR-0036); never a structural error. |
| `vaulter.security.accepted_risks` | — | `[]` | — |
| `vaulter.modules` | — | `[]` | Module activation overrides: module id => true\|false. Auto modules (media, drive, documents…) are active unless switched off; opt-in modules (webhooks) only when switched on. |
| `vaulter.routes.prefix` | `VAULTER_ROUTE_PREFIX` | `'api/vaulter'` | — |
| `vaulter.routes.middleware.authenticated` | — | `['api', 'auth:sanctum']` | — |
| `vaulter.routes.middleware.public` | — | `['api']` | — |
| `vaulter.routes.rate_limits.public` | `VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE` | `60` | — |
| `vaulter.routes.rate_limits.uploads` | `VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE` | `120` | — |
| `vaulter.outbox` | — | — | The transactional outbox: every domain event is written to v_outbox in the transaction of its operation; vaulter:relay hands it to each outbox consumer (webhooks.fanout, …) at least once. settle_window_seconds must exceed the longest Vaulter transaction: reconciliation only trusts events older than it, so a long transaction committing a smaller seq later is not skipped. |
| `vaulter.outbox.settle_window_seconds` | — | `300` | — |
| `vaulter.outbox.retention_seconds` | — | `604800` | — |
| `vaulter.outbox.max_consumer_hold_seconds` | — | `604800` | How long a paused consumer (switched off, missing from the module graph) holds retention before it is detached with an explicit gap. |
| `vaulter.outbox.dead_retention_seconds` | — | `2592000` | — |
| `vaulter.outbox.lease_seconds` | — | `60` | — |
| `vaulter.outbox.batch` | — | `100` | — |
| `vaulter.outbox.max_attempts` | — | `10` | — |
| `vaulter.outbox.backoff_base_seconds` | — | `2` | — |
| `vaulter.outbox.backoff_cap_seconds` | — | `3600` | — |
| `vaulter.idempotency` | — | — | Idempotency-Key receipts: how long a retry with the same key gets the stored outcome. vaulter:idempotency:prune deletes expired receipts. |
| `vaulter.idempotency.ttl_seconds` | — | `86400` | — |
| `vaulter.schedule` | — | — | Maintenance tasks put on the host scheduler. Value: null (off) \| everyMinute \| everyFiveMinutes hourly \| daily \| weekly \| 'dailyAt:HH:MM' \| 'hourlyAt:M' \| 'weeklyOn:D,HH:MM' \| a 5-field cron expression. Set enabled to false when the host schedules the commands itself. |
| `vaulter.schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | — |
| `vaulter.schedule.outbox_relay` | — | `'everyMinute'` | — |
| `vaulter.schedule.outbox_prune` | — | `'hourly'` | — |
| `vaulter.schedule.idempotency_prune` | — | `'dailyAt:03:30'` | — |
| `vaulter.observability.enabled` | `VAULTER_OBSERVABILITY_ENABLED` | `false` | — |
| `vaulter.observability.recorder` | `VAULTER_OBSERVABILITY_RECORDER` | `null` | — |
| `vaulter.observability.tracing.enabled` | `VAULTER_TRACING_ENABLED` | `false` | — |
| `vaulter.observability.tracing.recorder` | `VAULTER_TRACING_RECORDER` | `null` | — |
| `vaulter.models.outbox` | — | `OutboxEvent::class` | — |

### `vaulter-documents`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-documents.types` | — | `[RichTextDocumentType::class, MarkdownDocumentType::class]` | Document type classes; add your own here. |
| `vaulter-documents.default_type` | — | `'vaulter/rich-text'` | — |
| `vaulter-documents.collaboration.session_ttl` | — | `300` | — |
| `vaulter-documents.content.max_size_kb` | — | `5120` | — |
| `vaulter-documents.revisions.limit` | — | `50` | — |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | — | `300` | — |
| `vaulter-documents.comments.enabled` | — | `true` | — |
| `vaulter-documents.exporters` | — | `[HtmlExporter::class, MarkdownExporter::class]` | Document exporter classes, resolved by (document type, format). |
| `vaulter-documents.tiptap.extra_nodes` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_marks` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_attributes` | — | `[]` | — |
| `vaulter-documents.tiptap.url_attributes` | — | `[]` | — |
| `vaulter-documents.broadcast` | — | — | The private broadcast channels of a document (`<prefix>.<nodeId>`): the comment events use them, and the collaboration package authorizes them and names its rooms with the same prefix. |
| `vaulter-documents.broadcast.channel_prefix` | — | `'vaulter.documents'` | — |
| `vaulter-documents.models.document_revision` | — | `DocumentRevision::class` | — |
| `vaulter-documents.models.node_comment` | — | `NodeComment::class` | — |

### `vaulter-drive`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-drive.profiles.default` | — | `DefaultProfile::class` | — |
| `vaulter-drive.profiles.providers` | — | `[]` | — |
| `vaulter-drive.scan.mode` | — | `null` | — |
| `vaulter-drive.scan.required` | — | `null` | — |
| `vaulter-drive.scan.scanner` | — | `null` | — |
| `vaulter-drive.scan.scanner_key` | — | `null` | — |
| `vaulter-drive.scan.scanner_version` | — | `null` | — |
| `vaulter-drive.scan.policy_version` | — | `null` | — |
| `vaulter-drive.scan.rollout_key` | — | `null` | — |
| `vaulter-drive.features.grants` | — | `true` | — |
| `vaulter-drive.features.activity` | — | `true` | — |
| `vaulter-drive.features.changes` | — | `true` | The drive change journal behind DriveHandle::changes(). |
| `vaulter-drive.features.share_links` | — | `false` | — |
| `vaulter-drive.features.public_listing` | — | `false` | — |
| `vaulter-drive.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | HasDrives: delete the drives of an owner when the owner is deleted. |
| `vaulter-drive.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `'database'` | — |
| `vaulter-drive.access.permission_drivers.database` | — | `DatabasePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.gate` | — | `GatePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.spatie` | — | `SpatiePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.null` | — | `NullPermissionDriver::class` | — |
| `vaulter-drive.access.gate.abilities.previewer` | — | `'vaulter.preview'` | — |
| `vaulter-drive.access.gate.abilities.reader` | — | `'vaulter.view'` | — |
| `vaulter-drive.access.gate.abilities.commenter` | — | `'vaulter.comment'` | — |
| `vaulter-drive.access.gate.abilities.writer` | — | `'vaulter.edit'` | — |
| `vaulter-drive.access.gate.abilities.organizer` | — | `'vaulter.organize'` | — |
| `vaulter-drive.access.gate.abilities.owner` | — | `'vaulter.manage'` | — |
| `vaulter-drive.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `'vaulter.super'` | — |
| `vaulter-drive.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `'vaulter.create'` | — |
| `vaulter-drive.access.spatie.permissions.previewer` | — | `'vaulter.preview'` | — |
| `vaulter-drive.access.spatie.permissions.reader` | — | `'vaulter.view'` | — |
| `vaulter-drive.access.spatie.permissions.commenter` | — | `'vaulter.comment'` | — |
| `vaulter-drive.access.spatie.permissions.writer` | — | `'vaulter.edit'` | — |
| `vaulter-drive.access.spatie.permissions.organizer` | — | `'vaulter.organize'` | — |
| `vaulter-drive.access.spatie.permissions.owner` | — | `'vaulter.manage'` | — |
| `vaulter-drive.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `'vaulter.super'` | — |
| `vaulter-drive.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `'vaulter.create'` | — |
| `vaulter-drive.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` | — |
| `vaulter-drive.access.spatie.role_subject_type` | — | `'role'` | — |
| `vaulter-drive.access.spatie.team_subject_type` | — | `'team'` | — |
| `vaulter-drive.listing.scan_budget` | — | `1000` | — |
| `vaulter-drive.sharing.token_generator` | — | `RandomShareTokenGenerator::class` | — |
| `vaulter-drive.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `'/share'` | — |
| `vaulter-drive.sharing.prefix_length` | — | `8` | — |
| `vaulter-drive.sharing.default_ttl_hours` | `VAULTER_SHARE_DEFAULT_TTL_HOURS` | `72` | — |
| `vaulter-drive.sharing.max_ttl_hours` | `VAULTER_SHARE_MAX_TTL_HOURS` | `720` | — |
| `vaulter-drive.downloads.delivery_mode` | — | `'strict'` | — |
| `vaulter-drive.downloads.direct_ttl_seconds` | — | `60` | — |
| `vaulter-drive.downloads.inline_mime_types` | — | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` | — |
| `vaulter-drive.changes.retention_days` | — | `30` | Days a journal entry stays readable; vaulter:changes:prune deletes older ones and a cursor behind them expires (410 cursor_expired). |
| `vaulter-drive.changes.max_page_size` | — | `1000` | The largest page DriveHandle::changes()->list() returns. |
| `vaulter-drive.changes.key_generation` | — | `1` | Raise on every instance together to expire all cursors and sync keys (after key rotation or re-enabling the journal); clients bootstrap again. |
| `vaulter-drive.changes.cursor_max_age_seconds` | — | `86400` | Seconds a cursor lives after startCursor(): 1..2592000, at most the retention. |
| `vaulter-drive.handlers` | — | `[]` | — |
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
| `vaulter-drive.uploads.resumable.complete_timeout_seconds` | `VAULTER_UPLOAD_COMPLETE_TIMEOUT_SECONDS` | `60` | Finite monotonic budget of one completion request, DB retries included (1..300 s). |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` | `20` | — |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | — | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | — | `512` | — |
| `vaulter-drive.trash.retention_days` | — | `30` | — |
| `vaulter-drive.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-drive.schedule.changes_prune` | — | `'dailyAt:03:20'` | — |
| `vaulter-drive.schedule.uploads_sweep` | — | `'hourlyAt:15'` | — |
| `vaulter-drive.schedule.trash_purge` | — | `'dailyAt:03:30'` | — |
| `vaulter-drive.schedule.drives_purge` | — | `'hourlyAt:25'` | — |
| `vaulter-drive.schedule.revisions_prune` | — | `'dailyAt:03:40'` | — |
| `vaulter-drive.schedule.scan_reconcile` | — | `'everyFiveMinutes'` | — |
| `vaulter-drive.schedule.drives_recount` | — | `null` | Report-only drift check; off until an operator wants it. |
| `vaulter-drive.models.drive` | — | `Drive::class` | — |
| `vaulter-drive.models.node` | — | `Node::class` | — |
| `vaulter-drive.models.file` | — | `File::class` | — |
| `vaulter-drive.models.scan_obligation` | — | `ScanObligation::class` | — |
| `vaulter-drive.models.scan_request` | — | `ScanRequest::class` | — |
| `vaulter-drive.models.scan_evidence` | — | `ScanEvidenceRecord::class` | — |
| `vaulter-drive.models.scan_policy_state` | — | `ScanPolicyState::class` | — |
| `vaulter-drive.models.scan_backfill_run` | — | `ScanBackfillRun::class` | — |
| `vaulter-drive.models.file_revision` | — | `FileRevision::class` | — |
| `vaulter-drive.models.file_revision_blob` | — | `FileRevisionBlob::class` | — |
| `vaulter-drive.models.grant` | — | `NodeGrant::class` | — |
| `vaulter-drive.models.node_share` | — | `NodeShare::class` | — |
| `vaulter-drive.models.activity` | — | `Activity::class` | — |
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
| `vaulter-media.scrub` | — | — | The integrity scrub (vaulter:blobs:scrub): the sample and budget of one run — the first one used up ends it. Reading a whole object store is expensive, so a run covers a sample; null: no byte or time budget. Not scheduled. |
| `vaulter-media.scrub.limit` | `VAULTER_SCRUB_LIMIT` | `100` | — |
| `vaulter-media.scrub.max_bytes` | `VAULTER_SCRUB_MAX_BYTES` | `null` | — |
| `vaulter-media.scrub.max_seconds` | `VAULTER_SCRUB_MAX_SECONDS` | `null` | — |
| `vaulter-media.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-media.schedule.blobs_gc` | — | `'dailyAt:04:00'` | — |
| `vaulter-media.schedule.blobs_reconcile` | — | `'weeklyOn:0,04:30'` | — |
| `vaulter-media.content_store` | — | `Sha256ContentStore::class` | — |
| `vaulter-media.storage_driver` | — | `FlysystemStorageDriver::class` | — |
| `vaulter-media.blob_store` | — | `BlobStore::class` | — |
| `vaulter-media.models` | — | — | Swappable Eloquent model of the blob table (must extend the package model). |
| `vaulter-media.models.blob` | — | `Blob::class` | — |

### `vaulter-webhooks`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-webhooks.timeout` | — | `15` | — |
| `vaulter-webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days in total). Jitter is the +/- fraction applied to each delay. |
| `vaulter-webhooks.retry_jitter` | — | `0.1` | — |
| `vaulter-webhooks.disable_after_days` | — | `5` | Days of uninterrupted failure before an endpoint is disabled; 0 never disables. |
| `vaulter-webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (max 604800). |
| `vaulter-webhooks.retention_days` | — | `30` | — |
| `vaulter-webhooks.retry_claim_seconds` | — | `60` | — |
| `vaulter-webhooks.relay` | — | — | The webhooks.fanout outbox consumer materializes deliveries fanout_chunk endpoints at a time (1..1000), each chunk in its own transaction under the outbox delivery lease. |
| `vaulter-webhooks.relay.fanout_chunk` | — | `100` | — |
| `vaulter-webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `'vaulter.webhooks.manage'` | This ability authorizes management; strict tenancy still limits it to the current tenant's endpoints. Endpoints without a tenant need an explicit administrative migration before a tenant can manage them. |
| `vaulter-webhooks.delivery.url_guard` | — | `DefaultOutboundUrlGuard::class` | — |
| `vaulter-webhooks.delivery.allowed_schemes` | — | `['http', 'https']` | — |
| `vaulter-webhooks.delivery.blocked_ip_ranges` | — | `null` | — |
| `vaulter-webhooks.delivery.allowed_ip_ranges` | — | `[]` | — |
| `vaulter-webhooks.delivery.connect_timeout` | — | `2` | — |
| `vaulter-webhooks.delivery.max_response_bytes` | — | `65536` | — |
| `vaulter-webhooks.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-webhooks.schedule.webhooks_retry` | — | `'everyMinute'` | — |
| `vaulter-webhooks.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | — |
| `vaulter-webhooks.models.webhook_endpoint` | — | `WebhookEndpoint::class` | — |
| `vaulter-webhooks.models.webhook_delivery` | — | `WebhookDelivery::class` | — |
<!-- vaulter:config-reference:end -->


PLAN4-D20: `vaulter-drive.changes.key_generation` = `1` (положительное целое); повышайте на всех инстансах после выключения/включения функции или ротации ключа. `vaulter-drive.changes.cursor_max_age_seconds` = `86400`, диапазон 1..min(2592000, retention_days × 86400). Курсор сохраняет исходное время выпуска; страницы не продлевают deadline.
