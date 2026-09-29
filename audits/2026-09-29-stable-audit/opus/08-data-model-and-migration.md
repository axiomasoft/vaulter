# 08 — Модель данных, upgrade-миграция и каталог событий

Решения: [D16](02-decisions.md#d16), [D20](02-decisions.md#d20), [D22](02-decisions.md#d22),
[D23](02-decisions.md#d23), [D25](02-decisions.md#d25), [D27](02-decisions.md#d27).
Обозначения типов: **PK** — первичный ключ по `ids.strategy`; **REF** — ссылка на PK Vaulter;
**HK** — ключ хоста по `ids.host_keys` (по умолчанию `varchar(64)`).

## 1. Агрегаты

```
Owner (host) 1─* Drive 1─1 Root(Node)
Drive 1─* Node ── parent ──► Node
Node(kind=file)     1─1 File     1─* FileRevision 1─* FileRevisionBlob *─1 Blob
Node(kind=document) 1─1 Document 1─* DocumentRevision
Node 1─* NodeGrant, NodeShare, NodeComment
Drive 1─* Activity, UploadSession
```

Инварианты (проверяются в Actions и, где возможно, индексами):

| # | Инвариант | Где |
|---|---|---|
| I1 | У drive ровно один узел с `parent_id IS NULL` — его корень | уникальный частичный индекс `(drive_id) WHERE parent_id IS NULL` (MySQL — generated column) + Actions |
| I2 | Родитель любого не-корневого узла — папка того же drive | Actions (D13) |
| I3 | Имена активных соседей уникальны без учёта регистра | частичный уникальный индекс `(parent_id, lower(name)) WHERE trashed_at IS NULL` |
| I4 | Дерево ацикличное | tree lock + проверка в транзакции move |
| I5 | `kind=file` ⇔ есть строка `v_files`; `kind=document` ⇔ есть строка `v_documents` | Actions + doctor |
| I6 | `current_revision_id` агрегата указывает на ревизию этого же агрегата | Actions + doctor |
| I7 | `used_bytes` = сумма `size_bytes` голов **всех** файлов drive, включая корзину (как сейчас: освобождает только purge; копия добавляет байты) | условный UPDATE + `vaulter:drives:recount` |
| I8 | Каждая строка `v_file_revision_blobs` ссылается на committed blob | CAS commit + reconcile |

## 2. Целевая схема (изменённые таблицы)

### `v_drives` (было `v_storages`)

| Колонка | Тип | Примечание |
|---|---|---|
| `id` | PK | |
| `tenant_id` | HK null | |
| `owner_type` | varchar(191) | morph-алиас модели хоста |
| `owner_id` | HK | |
| `profile` | varchar(64) | было `panel` |
| `name` | varchar(255) | |
| `root_node_id` | REF null | |
| `disk` | varchar(64) | |
| `quota_bytes` | bigint null | |
| `used_bytes` | bigint default 0 | |
| `status` | varchar(16) default `active` | `active|archived|deleting` |
| `created_by` | HK null | |
| `created_at`, `updated_at` | timestamps | |

Индексы: `UNIQUE(owner_type, owner_id, profile)`; `(profile, status)`; `(tenant_id)`.
Удалены: `storage_type`, `slug`, `default_node_permissions`, `deleted_at`.

### `v_nodes`

Добавить: `sequence` unsigned int default 0. Переименовать: `storage_id` → `drive_id`, `owner_id` → `owned_by`
(HK; пользователь-владелец узла, не путать с владельцем drive). Изменить тип на HK: `owned_by`, `created_by`,
`updated_by`, `trashed_by`, `tenant_id`. `extra` — jsonb null всегда (флаг `extra_data` удалён).
Удалить: `current_version_id`, `file_id`, `deleted_at`.
Индексы: как сейчас + `UNIQUE (drive_id) WHERE parent_id IS NULL`; sibling-unique без `deleted_at`.

### `v_files`

`current_version_id` → `current_revision_id`; `version_count` → `revision_count`; удалить `deleted_at`;
`node_id` остаётся `UNIQUE`.

### `v_file_revisions` (было `v_file_versions`)

`version_no` → `number`; `content_sha256` → `sha256`; добавить `declared_mime_type varchar(191) null`,
`scan_status varchar(16) default 'not_required'`; `created_by` → HK. `UNIQUE(file_id, number)`.

### `v_file_revision_blobs` (было `v_file_version_blobs`)

`file_version_id` → `file_revision_id`. Остальное без изменений.

### `v_blobs`

`storage_id` → `drive_id` (REF null); `tenant_id` → HK null; добавить `scope_key varchar(191) NOT NULL`.
Уникальность: `UNIQUE(scope_key, sha256)`; `UNIQUE(storage_key)` остаётся как защита от двойного объекта;
удалить прежний `…_dedup_uniq` по колонкам области.

### `v_documents` (новая)

| Колонка | Тип | Примечание |
|---|---|---|
| `id` | PK | |
| `tenant_id` | HK null | |
| `node_id` | REF, UNIQUE, FK → `v_nodes` cascade | |
| `type` | varchar(64) | `vaulter/rich-text` |
| `schema_version` | int | версия схемы головы |
| `current_revision_id` | REF null | |
| `revision_count` | int default 0 | |
| `collab_epoch` | int default 0 | D24 |
| `created_at`, `updated_at` | timestamps | |

Индекс `(type)` — для doctor «документы неизвестных типов».

### `v_document_revisions` (было `v_node_document_versions`)

`node_id` → `document_id` (REF, FK → `v_documents` cascade); `version_no` → `number`; `snapshot_label` → `label`;
удалить `format`; добавить `schema_version int NOT NULL`; `created_by` → HK. `UNIQUE(document_id, number)`,
индекс `(document_id, is_autosave, created_at)`.

### Прочие

| Таблица | Изменение |
|---|---|
| `v_node_grants` | `subject_id`, `granted_by`, `tenant_id` → HK; удалить `deleted_at`, `inherited`, `inherited_from_node_id` (не используются); уникальность `(node_id, subject_type, subject_id, scope, effect)` — обычный UNIQUE; MySQL generated column `grant_active` удалить |
| `v_node_shares` | `granted_by`, `tenant_id` → HK; удалить `deleted_at` |
| `v_node_comments` | actor-колонки → HK |
| `v_upload_sessions`, `v_upload_parts` | `storage_id` → `drive_id`; `node_id` → `target_node_id`; actor → HK; добавить `result_node_id` REF null, `result_revision_id` REF null (идемпотентный `complete`, D19) |
| `v_activity` | `storage_id` → `drive_id`; `actor_id` → HK; добавить `event_id varchar(26) null` (индекс); значения `verb` переименовать по §6 |
| `v_outbox` | добавить `drive_id` REF null, `aggregate_type varchar(32)`, `aggregate_id varchar(64)`, `sequence bigint null` |

## 3. Upgrade-миграции 0.2 → 0.3

Если фаза F1 выпускается отдельно как 0.2.1 ([13-workstreams.md](13-workstreams.md)), миграции №3 (типы HK),
№7 (scope_key) и ремонт «вторых корней» из №6 уходят в 0.2.1 с теми же телами; остальные остаются в 0.3.

Порядок — отдельные миграции (PostgreSQL выполняет каждую в транзакции; на MySQL DDL не транзакционен, поэтому
каждая миграция идемпотентна: проверяет состояние перед шагом). Все читают **старые** имена таблиц из
устаревших `vaulter.table_names`/`vaulter.table_prefix`, если они заданы.

| # | Файл | Шаги |
|---|---|---|
| 1 | `2026_10_01_000001_vaulter_preflight.php` | Отказ с понятным сообщением, если: есть `v_nodes.deleted_at IS NOT NULL`; есть узлы `kind=document` без строки ревизий; есть собственные таблицы панелей (own-tables). Подсказка: `php artisan vaulter:upgrade:inspect` |
| 2 | `…_000002_rename_storages_to_drives.php` | `v_storages` → `v_drives`; `panel` → `profile`; все `storage_id` → `drive_id`; удалить мёртвые колонки |
| 3 | `…_000003_host_key_columns.php` | Типы HK-колонок по `ids.host_keys` (PG: `ALTER COLUMN … TYPE varchar(64) USING …::text`; MySQL: `MODIFY`); кастомная `tenant.column` → `tenant_id` |
| 4 | `…_000004_file_revisions.php` | Переименование таблиц/колонок файлов; новые колонки; `scan_status = 'not_required'` для существующих |
| 5 | `…_000005_documents_aggregate.php` | Создать `v_documents`; для каждого `kind=document`: тип из `format` головы (`richtext|table|template` → `vaulter/rich-text`, `markdown` → `vaulter/markdown`, `univer` → `vaulter/spreadsheet`, иное → как есть с префиксом `legacy/`), `schema_version = 1`, `current_revision_id = nodes.current_version_id`, `revision_count = max(version_no)`; переименовать таблицу ревизий, заполнить `document_id`, `schema_version = 1`; удалить `node_id`, `format` |
| 6 | `…_000006_nodes_cleanup.php` | `sequence = 0`; `owner_id` → `owned_by`; удалить `current_version_id`, `file_id`, `deleted_at`; **ремонт N04**: не-корневые узлы с `parent_id IS NULL` перенести под корень их drive (конфликт имени → суффикс ` (legacy N)`), записать в activity `node.moved` от `vaulter:system`; создать индекс единственного корня |
| 7 | `…_000007_blob_scope_key.php` | Добавить `scope_key`, заполнить: `global` → `global`; `tenant` → `tenant:{tenant_id}` или `legacy:tnull`; `storage` → `drive:{drive_id}` или `legacy:snull`; затем `UNIQUE(scope_key, sha256)`, удалить старый dedup-индекс |
| 8 | `…_000008_grants_shares_cleanup.php` | Жёстко удалить soft-deleted grants (история в activity); `revoked_at = coalesce(revoked_at, deleted_at)` у shares; удалить `deleted_at`; пересоздать уникальность grants |
| 9 | `…_000009_events.php` | Колонки outbox/activity; `UPDATE v_activity SET verb = …` по карте §6 |

После миграций — команды (не миграции, потому что работают с объектным хранилищем):

- `vaulter:blobs:repair-scopes --dry-run` → отчёт по `legacy:snull|tnull` blobs и их ссылкам;
  `--execute` → перенос в правильные области (D20); идемпотентен, возобновляем, ведёт журнал в `storage/logs`.
- `vaulter:drives:recount [--drive=]` → пересчёт `used_bytes` (I7).
- `vaulter:doctor --production` → должен пройти без ошибок.

Откат: `down()` каждой миграции восстанавливает структуру; данные ремонта N04 и hard-delete grants
необратимы — это отмечено в UPGRADE.md, preflight-миграция требует бэкап (флаг `--force` при наличии данных).

## 4. `VaulterDatabase` (D25)

```php
namespace Vaulter\Database;

final class VaulterDatabase
{
    public function connection(): ConnectionInterface;                 // vaulter.database.connection
    public function driver(): string;                                  // pgsql|mysql|mariadb|sqlite
    /** Ретраи на serialization failure/deadlock (PG 40001/40P01, MySQL 1213/1205). */
    public function transaction(Closure $callback, int $attempts = 3): mixed;
    /** Блокировка дерева drive до конца транзакции. PG: pg_advisory_xact_lock; иначе SELECT … FOR UPDATE строки v_drives. */
    public function lockTree(int|string $driveId): void;
    /** Транзакционная advisory-блокировка по строковому ключу (CAS). */
    public function advisoryLock(string $key): void;
    public function afterCommit(Closure $callback): void;              // на соединении Vaulter
}
```

Все прежние вызовы `DB::transaction`, `DB::connection()`, `DB::afterCommit` в `src` заменяются. Arch-тест
запрещает импорт `Illuminate\Support\Facades\DB` вне `Database\` и `Schema\`.

## 5. Порядок блокировок (D21)

```
1. VaulterDatabase::lockTree(drive)            — только операции, меняющие структуру (move, restore, purge поддерева, delete drive)
2. SELECT … FOR UPDATE узлов по возрастанию id  — узел операции и назначение
3. SELECT … FOR UPDATE агрегата                 — v_files / v_documents (ревизии, голова)
4. UPDATE v_drives … used_bytes (условный)      — квота
5. VaulterDatabase::advisoryLock("cas:{scope_key}:{sha256}") — commit blob
```

Операция не берёт блокировку уровня N после блокировки уровня M > N. Тест на PG: два параллельных сценария с
пересекающимися ресурсами завершаются без deadlock или с одним ретраем.

## 6. Каталог событий (`Vaulter\Enums\Events\EventType`)

Общие поля всех событий (конверт webhook и колонки activity): `id` (occurrence ULID), `type`, `source = "vaulter"`,
`time`, `drive_id`, `subject` (id узла или ресурса), `sequence` (узла, если применимо), `actor {type, id}`,
`correlation_id`, `data`.

| `EventType` | Класс | `data` | activity | webhook |
|---|---|---|---|---|
| `drive.created` | `Drive\Events\Drives\DriveCreated` | owner, profile, root_node_id | ✓ | ✓ |
| `drive.archived` / `drive.deleted` | `Drive\Events\Drives\Drive{Archived,Deleted}` | — | ✓ | ✓ |
| `folder.created` | `Drive\Events\Nodes\FolderCreated` | parent_id, name | ✓ | ✓ |
| `file.uploaded` | `Drive\Events\Files\FileUploaded` | parent_id, name, mime, size, revision_id | ✓ | ✓ |
| `file.revision_added` | `Drive\Events\Files\FileRevisionAdded` | revision_id, number, size, previous_size | ✓ | ✓ |
| `node.renamed` | `Drive\Events\Nodes\NodeRenamed` | from, to | ✓ | ✓ |
| `node.moved` | `Drive\Events\Nodes\NodeMoved` | from_parent_id, to_parent_id | ✓ | ✓ |
| `node.copied` | `Drive\Events\Nodes\NodeCopied` | source_id, copy_id, destination_id | ✓ | ✓ |
| `node.updated` | `Drive\Events\Nodes\NodeUpdated` | changed: pinned/position | ✓ | — |
| `node.trashed` | `Drive\Events\Nodes\NodeTrashed` | descendant_count | ✓ | ✓ |
| `node.restored` | `Drive\Events\Nodes\NodeRestored` | destination_id, renamed_to | ✓ | ✓ |
| `node.purged` | `Drive\Events\Nodes\NodePurged` | node_ids (≤ 1000, иначе count) | ✓ | ✓ |
| `upload.initiated` / `upload.aborted` / `upload.expired` | `Drive\Events\Uploads\*` | upload_id, protocol, size | ✓ | — |
| `document.created` | `Documents\Events\Documents\DocumentCreated` | type, revision_id | ✓ | ✓ |
| `document.updated` | `Documents\Events\Documents\DocumentContentUpdated` | revision_id, autosave | — (шум) | ✓ (debounced: только новая ревизия) |
| `document.revision_created` | `Documents\Events\Revisions\DocumentRevisionCreated` | revision_id, label, keep_forever | ✓ | ✓ |
| `document.restored` | `Documents\Events\Revisions\DocumentRestored` | from_revision_id, new_revision_id, collab_epoch | ✓ | ✓ |
| `comment.added` / `comment.resolved` / `comment.deleted` | `Documents\Events\Comments\*` | comment_id | ✓ | ✓ |
| `grant.created` / `grant.revoked` | `Vaulter\Events\Access\NodeGrant{Created,Revoked}` | subject, level, effect, scope | ✓ | ✓ |
| `share.created` / `share.revoked` | `Vaulter\Events\Sharing\NodeShare{Created,Revoked}` | share_id, level, expires_at (без токена) | ✓ | ✓ |

Правила: payload не содержит сырых токенов, паролей, содержимого документов и путей объектного хранилища;
`EventType` — закрытый enum для first-party событий; сторонние события в 1.0 не участвуют в webhooks
(T2: реестр пользовательских типов).
