# 10 — REST API v1

Решения: [D29](02-decisions.md#d29)–[D31](02-decisions.md#d31), [D33](02-decisions.md#d33).

## 1. Базовые правила

| Правило | Значение |
|---|---|
| Базовый путь | `/{routes.prefix}/v1` → по умолчанию `/api/vaulter/v1` |
| Версионирование | мажор в URL; аддитивные изменения без смены версии; удаление/смена смысла — только v2; окно поддержки v1 — минимум 12 месяцев после выхода v2 |
| Заголовок версии | каждый ответ: `X-Vaulter-Api-Version: 1` |
| Имена маршрутов | `vaulter.v1.<resource>.<action>`; snapshot-тест списка маршрутов |
| JSON | `snake_case`; все ID — строки; время — RFC 3339 UTC; `data` для ресурса/списка |
| Списки | курсорные: `?cursor=&per_page=` (1..200, по умолчанию 50) → `{ "data": [...], "meta": { "next_cursor": "…"|null, "per_page": 50 } }`; без `total` |
| Оптимистичная блокировка | ответ с узлом несёт `ETag: "{id}.{sequence}"`; `PATCH`/`move`/`PUT content`/`trash` принимают `If-Match`; несовпадение → 412 `node_sequence_mismatch` |
| Ошибки | `application/problem+json` (§6) |
| Корреляция | входящий `X-Request-Id` (валидный ≤ 64 символа) или новый ULID; эхо в ответе; попадает в activity/outbox |
| Тела загрузки | single upload — `multipart/form-data`; остальное — JSON |

## 2. Стеки middleware

| Стек | По умолчанию | Маршруты |
|---|---|---|
| `authenticated` | `['api', 'auth:sanctum']` + `AssignCorrelationId`, `RecordVaulterRequestMetrics` | все, кроме ниже |
| `public` | `['api']` + `throttle:vaulter-public`, `ResolveShareToken` | `shares/{token}*` |
| `hub` | `['api']` + `throttle:vaulter-public`, `ResolveCollaborationCall` | `collaboration/hub/*` |

Uploads дополнительно `throttle:vaulter-uploads`. Метрики и correlation id добавляются пакетом ко всем трём
стекам автоматически (хост их не перечисляет).

## 3. Резолв ресурсов вместо глобальных биндингов

Глобальные `Route::bind('node'|'storage')` удаляются (N10). Параметры маршрутов — `{drive}`, `{node}`,
`{revision}`, `{grant}`, `{share}`, `{comment}`, `{upload}` — скаляры. `HttpResourceResolver`
(core `Http\Support`) выдаёт модели с фильтром по tenant (D10), а для вложенных — по родителю
(`revision` принадлежит агрегату `node`, `grant`/`share`/`comment` — узлу). Не найдено → 404 `node_not_found`
(или `*_not_found` соответствующего ресурса). Контроллер получает handle:
`$drive = Vaulter::drive($resolvedDrive)` (actor — пользователь запроса через guard стека).

## 4. Карта маршрутов (было → стало)

Префикс `/api/vaulter` опущен; «было» — без `/v1`.

### Drive и дерево (пакет drive)

| Было | Стало | Имя | Контроллер (`Vaulter\Drive\Http\Controllers\…`) |
|---|---|---|---|
| `GET storages/{storage}` | `GET v1/drives/{drive}` | `drives.show` | `Drives\ShowDriveController` |
| `GET storages/{storage}/nodes?parent_id=` | `GET v1/drives/{drive}/nodes?parent=` | `nodes.index` | `Nodes\ListChildrenController` |
| `GET storages/{storage}/search?q=` | `GET v1/drives/{drive}/search?q=` | `nodes.search` | `Search\SearchNodesController` |
| `GET storages/{storage}/trash` | `GET v1/drives/{drive}/trash` | `trash.index` | `Trash\ListTrashController` |
| — | `DELETE v1/drives/{drive}/trash` | `trash.empty` | `Trash\EmptyTrashController` |
| `POST storages/{storage}/folders` | `POST v1/drives/{drive}/folders` | `folders.store` | `Folders\CreateFolderController` |
| `POST storages/{storage}/uploads` | `POST v1/drives/{drive}/files` | `files.store` | `Files\UploadFileController` |
| `GET shared` | `GET v1/shared` | `shared.index` | `Shared\ListSharedWithMeController` |
| `GET nodes/{node}` | `GET v1/nodes/{node}` | `nodes.show` | `Nodes\ShowNodeController` |
| `PATCH nodes/{node}` (rename) | `PATCH v1/nodes/{node}` (`name`, `pinned`, `position`) | `nodes.update` | `Nodes\UpdateNodeController` |
| `POST nodes/{node}/move` | `POST v1/nodes/{node}/move` (`destination_id`) | `nodes.move` | `Nodes\MoveNodeController` |
| `POST nodes/{node}/copy` | `POST v1/nodes/{node}/copy` (`destination_id`, `name`) | `nodes.copy` | `Nodes\CopyNodeController` |
| `DELETE nodes/{node}` (в корзину) | `POST v1/nodes/{node}/trash` | `nodes.trash` | `Trash\TrashNodeController` |
| `POST nodes/{node}/restore` | `POST v1/nodes/{node}/restore` (`destination_id?`, `on_conflict?`) | `nodes.restore` | `Trash\RestoreNodeController` |
| `DELETE nodes/{node}/force` | `DELETE v1/nodes/{node}` (только из корзины, иначе 409 `node_not_trashed`) | `nodes.purge` | `Trash\PurgeNodeController` |
| — | `GET v1/nodes/{node}/abilities?explain={ability}` | `nodes.abilities` | `Nodes\ShowAbilitiesController` (explain требует Manage) |
| `GET nodes/{node}/download` | `GET v1/files/{node}/download` | `files.download` | `Files\DownloadFileController` |
| — | `GET v1/files/{node}/revisions` | `files.revisions.index` | `Files\ListFileRevisionsController` |
| (новая версия шла через `uploads` с target) | `POST v1/files/{node}/revisions` (multipart) | `files.revisions.store` | `Files\AddFileRevisionController` |
| `GET nodes/{node}/versions/{version}/download` | `GET v1/files/{node}/revisions/{revision}/download` | `files.revisions.download` | `Files\DownloadFileRevisionController` |
| — | `POST v1/files/{node}/revisions/{revision}/restore` | `files.revisions.restore` | `Files\RestoreFileRevisionController` |

Типизированные коллекции: операции, общие для всех узлов, живут под `v1/nodes/{node}`; операции над
содержимым конкретного kind — под `v1/files/{node}` (drive) и `v1/documents/{node}` (documents). `{node}` —
всегда ID узла; несоответствие kind → 409 `not_a_file` / `not_a_document`. Так пакеты не делят один URL и
не нуждаются в делегировании маршрутов друг другу.

### Загрузки (drive)

| Было | Стало | Имя |
|---|---|---|
| `POST storages/{storage}/resumable-uploads` | `POST v1/drives/{drive}/uploads` (`protocol`, `parent_id` \| `node_id`, `name`, `size`, `mime`) | `uploads.store` |
| `GET resumable-uploads/{upload_id}` | `GET v1/uploads/{upload}` | `uploads.show` |
| `HEAD/PATCH/DELETE/OPTIONS resumable-uploads/{upload_id}` | `HEAD/PATCH/DELETE/OPTIONS v1/uploads/{upload}` (tus) | `uploads.tus.*` |
| `POST resumable-uploads/{upload_id}/parts` | `POST v1/uploads/{upload}/parts` | `uploads.parts.store` |
| `POST resumable-uploads/{upload_id}/complete` | `POST v1/uploads/{upload}/complete` | `uploads.complete` |

### Доступ, шаринг, activity (core)

| Было | Стало | Имя |
|---|---|---|
| `GET nodes/{node}/grants` | `GET v1/nodes/{node}/grants` | `grants.index` |
| `POST nodes/{node}/grants` | `POST v1/nodes/{node}/grants` | `grants.store` |
| `DELETE nodes/{node}/grants` (тело с субъектом) | `DELETE v1/nodes/{node}/grants/{grant}` | `grants.destroy` |
| `GET/POST nodes/{node}/shares` | `GET/POST v1/nodes/{node}/shares` | `shares.index/store` |
| `DELETE nodes/{node}/shares/{share}` | `DELETE v1/nodes/{node}/shares/{share}` | `shares.destroy` |
| `GET storages/{storage}/activity` | `GET v1/drives/{drive}/activity` | `activity.drive` |
| `GET nodes/{node}/activity` | `GET v1/nodes/{node}/activity` | `activity.node` |
| `GET share/{token}` | `GET v1/shares/{token}` (public) | `public-shares.show` |
| `GET share/{token}/nodes` | `GET v1/shares/{token}/nodes` | `public-shares.nodes` |
| `GET share/{token}/download` | `GET v1/shares/{token}/download?node=` | `public-shares.download` |
| `* webhook-endpoints…` | `* v1/webhook-endpoints…` (как сейчас) | `webhook-endpoints.*` |

### Документы (documents)

| Было | Стало | Имя |
|---|---|---|
| `POST storages/{storage}/documents` | `POST v1/drives/{drive}/documents` (`type`, `name`, `parent_id`, `content?`) | `documents.store` |
| `GET nodes/{node}/content` | `GET v1/documents/{node}/content` (голова: `{type, schema_version, revision_id, content}`) | `documents.content.show` |
| `PATCH nodes/{node}/content` | `PUT v1/documents/{node}/content` (+ `If-Match`) | `documents.content.update` |
| `GET nodes/{node}/document-versions` | `GET v1/documents/{node}/revisions` | `documents.revisions.index` |
| `POST nodes/{node}/document-versions` | `POST v1/documents/{node}/revisions` (`label`, `keep_forever`) | `documents.revisions.store` |
| — | `GET v1/documents/{node}/revisions/{revision}` (с контентом) | `documents.revisions.show` |
| `POST nodes/{node}/document-versions/{version}/restore` | `POST v1/documents/{node}/revisions/{revision}/restore` | `documents.revisions.restore` |
| `PATCH nodes/{node}/document-versions/{version}/keep-forever` | `PATCH v1/documents/{node}/revisions/{revision}` (`keep_forever`, `label`) | `documents.revisions.update` |
| `GET nodes/{node}/handler` | удалить: `presentation` приходит в ресурсе узла | |
| `GET nodes/{node}/export?format=` (JSON) | `GET v1/documents/{node}/export/{format}` (файл, `Content-Disposition`) | `documents.export` |
| `POST nodes/{node}/collab/token` | `POST v1/documents/{node}/collaboration/session` | `documents.collaboration.session` |
| `POST collab/authenticate` | `POST v1/collaboration/hub/authenticate` (hub) | `collaboration.hub.authenticate` |
| `POST collab/store` | `POST v1/collaboration/hub/store` (hub) | `collaboration.hub.store` |
| `GET/POST nodes/{node}/comments` | `GET/POST v1/nodes/{node}/comments` | `comments.index/store` |
| `POST nodes/{node}/comments/{comment}/resolve` | `POST v1/nodes/{node}/comments/{comment}/resolve` | `comments.resolve` |
| `DELETE nodes/{node}/comments/{comment}` | `DELETE v1/nodes/{node}/comments/{comment}` | `comments.destroy` |

Маршруты drive и documents регистрируются всегда, когда пакет установлен и `vaulter.routes.enabled = true`.
Фича-флаги (глобальные и профиля) проверяет Operations, а не условная регистрация маршрутов: так
`route:cache` не замораживает флаги, а поведение одинаково для HTTP и PHP. Выключенная фича на видимом
узле → 403 `profile_restriction`; на невидимом — обычный 404 `node_not_found`.

## 5. Ресурсы (JSON)

`Node`:

```json
{
  "id": "01J…", "drive_id": "01J…", "parent_id": "01J…", "kind": "file",
  "name": "contract.pdf", "mime": "application/pdf", "extension": "pdf", "size_bytes": 1024,
  "sequence": 7, "etag": "\"01J….7\"", "pinned": false, "position": 0,
  "trashed_at": null, "created_at": "…", "updated_at": "…", "owned_by": "42",
  "presentation": {
    "handler": "vaulter/preview", "document_type": null, "type_available": true,
    "viewer": { "key": "vaulter/pdf-preview", "props": {} },
    "capabilities": { "editable": false, "previewable": true, "downloadable": true, "thumbnailable": false, "collaborative": false }
  },
  "abilities": { "view": true, "download": true, "comment": false, "edit": true, "create": false, "move": true, "copy": true, "trash": true, "restore": true, "share": false, "manage": false, "delete": false },
  "scan_status": "not_required",
  "document": null
}
```

Для `kind=document`: `"document": { "type": "vaulter/rich-text", "schema_version": 1, "revision_id": "…", "collab_epoch": 3 }`.
Опциональные включения: `?include=breadcrumb,children` (как сейчас `breadcrumb`/`children`, но только по запросу).
Удалены поля `file_id`, `current_version_id`, `storage_id` (→ `drive_id`), `is_pinned` (→ `pinned`).

`Drive`: `id, owner {type, id}, profile, name, root_node_id, quota_bytes, used_bytes, status, created_at`.
`FileRevision`: `id, number, size_bytes, mime, declared_mime, sha256, original_name, scan_status, created_by, created_at, is_head`.
`DocumentRevision`: `id, number, schema_version, label, is_autosave, keep_forever, content_hash, created_by, created_at, is_head` (+ `content` в `show`).
`Grant`, `Share`, `Activity`, `UploadSession`, `Comment` — как сейчас, с переименованиями полей по словарю и строковыми ID.

## 6. Ошибки (RFC 9457)

```json
HTTP/1.1 409 Conflict
Content-Type: application/problem+json

{
  "type": "https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#node_name_conflict",
  "title": "Node name conflict",
  "status": 409,
  "code": "node_name_conflict",
  "detail": "A node named \"contract.pdf\" already exists in this folder.",
  "request_id": "01J…",
  "errors": null,
  "meta": { "parent_id": "01J…", "name": "contract.pdf" }
}
```

`code` — контракт SemVer; `title`/`detail` — человекочитаемые, могут меняться и локализоваться (`lang/`).
`meta` никогда не содержит путей хранилища, tenant id, токенов, SQL. Валидация FormRequest →
422 `validation_failed` c `errors` в формате Laravel.

| Код | HTTP | Исключение |
|---|---|---|
| `actor_required` | 401 | `MissingActorException` |
| `permission_denied` | 403 | `PermissionDeniedException` |
| `profile_restriction` | 403 | `ProfileRestrictionException` |
| `node_not_found`, `drive_not_found`, `revision_not_found`, `document_revision_not_found`, `grant_not_found`, `share_not_found`, `comment_not_found`, `upload_not_found` | 404 | `*NotFoundException` |
| `node_name_conflict` | 409 | `NodeNameConflictException` |
| `node_move_cycle` | 409 | `NodeCycleException` |
| `invalid_destination` | 409 | `InvalidDestinationException` |
| `cross_drive_operation` | 409 | `CrossDriveOperationException` |
| `node_not_trashed` | 409 | `NodeNotTrashedException` |
| `not_a_file`, `not_a_document`, `unsupported_node_kind` | 409 | … |
| `document_type_unavailable` | 409 | `DocumentTypeUnavailableException` |
| `collaboration_epoch_mismatch` | 409 | `CollaborationEpochMismatchException` |
| `upload_conflict`, `upload_offset_mismatch` | 409 | `Uploads\*` |
| `node_sequence_mismatch` | 412 | `StaleNodeException` |
| `quota_exceeded` | 413 | `QuotaExceededException` |
| `upload_rejected` (`meta.reason`) | 422 | `UploadRejectedException` |
| `invalid_document_content` (`meta.reason`) | 422 | `InvalidDocumentContentException` |
| `unknown_document_type`, `unknown_export_format` | 422 | … |
| `content_unavailable` | 423 | `ContentUnavailableException` |
| `rate_limited` | 429 | throttle |
| `internal_error` | 500 | любое неожиданное (детали — только в логе) |

Рендер: `Vaulter\Http\Problems\ProblemRenderer` регистрируется через `ExceptionHandler::renderable()` и
срабатывает, только если запрос попал в маршрут `vaulter.v1.*` (хостовые маршруты не затрагиваются).

## 7. OpenAPI и контрактные тесты

- Файл `openapi/vaulter-v1.yaml` (OpenAPI 3.1) в корне репозитория — источник истины REST-контракта.
- Контрактный тест (Pest, core + drive + documents): для каждого маршрута `vaulter.v1.*` есть операция в
  спецификации (сравнение по имени маршрута в `operationId`); ответы feature-тестов валидируются по схеме
  (`league/openapi-psr7-validator` в `require-dev`).
- Snapshot списка маршрутов (`php artisan route:list --name=vaulter.v1 --json` → golden).
- `oasdiff breaking` в CI против спецификации последнего тега: breaking-изменение v1 валит сборку.
- `@vaulter/vue` генерирует `src/api/schema.d.ts` через `openapi-typescript`; CI проверяет, что сгенерированный
  файл совпадает с закоммиченным.
