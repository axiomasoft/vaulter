---
title: Узлы-документы
description: Создание, редактирование и версионирование узлов-документов — рабочее состояние, неизменяемые чекпоинты, восстановление и прунинг — поверх REST-поверхности Documents.
outline: deep
---

# Узлы-документы

**Узел-документ** — это запись с `kind=document` в едином дереве узлов (`v_nodes`), полезная
нагрузка которой живёт в **агрегате документа**: рабочее состояние в `v_documents` и неизменяемые
чекпоинты в `v_document_revisions`. Он
живёт в том же дереве, что папки и файлы, поэтому Drive Actions (перемещение / переименование /
корзина / восстановление), а также стражи квоты и уникальности среди соседей применяются к нему
без изменений — документ это узел с приложенным агрегатом.

Эта страница описывает создание узлов-документов, чтение и автосохранение их контента, модель
**рабочее состояние против чекпоинтов**, восстановление предыдущего чекпоинта и то, как история
**прунится** (с пинами `keep_forever`). Про слой маршрутизации по форматам — в каком редакторе
открывается узел — см. [Document Handlers](./document-handlers.md). Про рендер контента в
HTML/Markdown — см. [Экспорт](./export.md).

::: info Документы доступны при установке пакета
Установите `axioma-studio/vaulter-documents`. Редактирование, ревизии и маршруты
документов доступны без флага `document_versions`. Коллаборация включается отдельно
через `vaulter-documents.collaboration.enabled`.
:::

## Модель данных

Документ состоит из двух частей (ADR-0022, поправка D34):

- **Рабочее состояние** — одна строка `v_documents` на узел `kind=document` (`node_id`
  уникален, каскад при удалении узла). Это контент, который сейчас видит любой редактор; каждое
  сохранение его перезаписывает.
- **Чекпоинты** — строки `v_document_revisions`. Чекпоинт **неизменяем**: после INSERT меняется
  только `keep_forever`, поэтому id ревизии всегда означает одни и те же байты. Автосохранённые
  чекпоинты снимаются по интервалу, именованные — по запросу, восстановление копирует старый
  чекпоинт вперёд.

Указатель головы живёт в агрегате (`v_documents.current_revision_id`), а не на узле: у `v_nodes`
больше нет `current_version_id`/`file_id`, поэтому ревизия файла и ревизия документа с одинаковым
id не путаются. На PostgreSQL и MySQL составной внешний ключ `(current_revision_id, id) →
v_document_revisions(id, document_id)` с `ON DELETE RESTRICT` гарантирует, что голова — чекпоинт
этого же документа, и не даёт прунингу её удалить (SQLite — см.
[Совместимость с БД](./database-compatibility.md)).

Колонки `v_documents`:

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | по стратегии id | Первичный ключ. |
| `tenant_id` | ключ хоста, nullable | Только при включённой тенантности. |
| `node_id` | FK → `v_nodes`, уникален | Узел `kind=document` (каскад при удалении). |
| `type` | string(64) | Ключ типа документа: `vaulter/rich-text`, `vaulter/markdown`, `vaulter/spreadsheet`. |
| `schema_version` | integer | Версия схемы контента рабочего состояния. |
| `current_revision_id` | ссылка, nullable | Чекпоинт-голова. |
| `revision_count` | integer | Монотонный счётчик чекпоинтов; прунинг его не уменьшает, номер не переиспользуется. |
| `collab_epoch` | BIGINT ≥ 0 | Поколение коллаборации; растёт при восстановлении и при замене JSON у документа с Yjs. |
| `state_version` | BIGINT ≥ 0 | Валидатор контента; +1 на каждую эффективную запись рабочего состояния. |
| `checkpoint_state_version` | BIGINT ≥ 0 | `state_version`, захваченный головой. |
| `content`, `content_hash`, `content_size` | json, char(64), integer | Каноническая JSON-проекция, её хеш и размер в байтах. |
| `yjs_state`, `yjs_state_size` | binary, integer | Состояние Yjs CRDT (источник истины коллаборации) и его размер. |
| `last_checkpoint_at` | timestamp | Когда снят последний чекпоинт. |
| `created_by`, `updated_by`, метки времени | | Аудит. |

Колонки `v_document_revisions`: `id`, `tenant_id`, `document_id` (FK, каскад), `number`
(уникален в документе), `schema_version`, `content`, `content_hash`, `content_size`, `yjs_state`,
`yjs_state_size`, `state_version` (захваченная версия рабочего состояния), `label`, `is_autosave`,
`keep_forever`, `status` (`committed`), `restored_from_revision_id` (FK, set null) и
`created_by`/`created_at`.

Валидатор контента **не зависит от узла**: `v_nodes.sequence` растёт при переименовании,
перемещении и корзине, `state_version` — при записи контента. Переименование документа не
инвалидирует открытый редактор, а сохранение не инвалидирует переименование (PLAN2.D35).
Полная матрица писателей и форматы ETag — в [Конкурентности](../reference/concurrency.md).

## PHP API

Публичный PHP API — `Documents::in($drive)` и `Documents::comments($drive)`. Они принимают хэндл
диска (`Vaulter::drive($owner)`, при необходимости `->actingAs($user)` или `->asSystem($reason)`) и
возвращают `Vaulter\Documents\Operations\DocumentOperations` и `CommentOperations`. Каждый метод
находит узел в этом диске и авторизует его тем же стражем, что и файлы: узел в корзине, узел
другого диска или тенанта и узел, который актор не видит, бросают `NodeNotFoundException`
(`404 node_not_found`); видимый узел без нужной способности — `PermissionDeniedException`
(`403 permission_denied`); ограничение профиля — `ProfileRestrictionException`.

| Метод | Требует | Возвращает |
|---|---|---|
| `create($name, $type = 'vaulter/rich-text', ?array $content, $parent, ?CreateFolderOptions)` | `Create` на родителе, типы документов профиля | `NodeSnapshot` |
| `content($document, $revision = null)` | `View` | `DocumentSnapshot` |
| `update($document, array $content, ?int $expectedStateVersion)` | `Edit` | `DocumentSnapshot` |
| `snapshot($document, ?string $label, bool $keepForever)` | `Edit` | `DocumentRevisionSnapshot` |
| `revisions($document, ?ListOptions)` | `View` | `Page<DocumentRevisionSnapshot>`, сначала новые |
| `restore($document, $revision, ?int $expectedStateVersion)` | `Edit` | `DocumentSnapshot` |
| `setRetention($document, $revision, bool $keepForever)` | `Edit` | `DocumentRevisionSnapshot` |
| `export($document, string $format, $revision = null)` | `Download` | `DocumentExport` |
| `collaborationSession($document)` | `View` (чтение-запись при `Edit`); пользователь, не система | `CollaborationSession` |

Комментарии (узел любого вида; профиль диска должен разрешать комментарии):

| Метод | Требует | Возвращает |
|---|---|---|
| `list($node, ?ListOptions)` | `View` | `Page<CommentSnapshot>`, сначала старые |
| `add($node, AddCommentInput $input)` | `Comment`; автор — действующий пользователь | `CommentSnapshot` |
| `resolve($node, $comment)` | `Comment`, и авторство или `Edit` | `CommentSnapshot` |
| `delete($node, $comment)` | авторство или `Manage` | — |

Узлы, ревизии и комментарии передаются id (строка или int) или своим снапшотом. Ревизия или
комментарий другого узла — «не найдено» (`document_revision_not_found`, `comment_not_found`).
Экспорту нужен `Download`, а не `View`: previewer читает документ в приложении, но не может вынести
копию.

```php
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Facades\Vaulter;

$drive = Vaulter::drive($project)->actingAs($user);
$documents = Documents::in($drive);

$node = $documents->create('Design notes', content: ['type' => 'doc', 'content' => []]);
$doc = $documents->content($node->id);            // DocumentSnapshot: content, stateVersion, epoch, …
$doc = $documents->update($node->id, $tipTapDoc, expectedStateVersion: $doc->stateVersion);
$named = $documents->snapshot($node->id, 'Before review', keepForever: true);
$html = $documents->export($node->id, 'html')->content;

Documents::comments($drive)->add($node->id, new AddCommentInput('Looks good', anchor: ['from' => 1, 'to' => 4]));
```

`DocumentSnapshot` несёт `nodeId`, `documentId`, `type`, `schemaVersion`, `typeAvailable`,
`editable` (false для исторической ревизии, неустановленного типа или содержимого, прочитанного
типом лишь частично — см. [Типы документов](./document-types.md)), `epoch`, `stateVersion`,
`dirty` (рабочее состояние опережает чекпоинт-голову), `headRevisionId`, `content`
(`DocumentContent` типа: `TipTapContent` для rich text и markdown, `OpaqueContent` для
spreadsheet или неустановленного типа), `revision`
(голова или запрошенная ревизия), `contentHash`, `yjsStateSize`, `updatedAt` и `updatedBy`. Id —
строки. Actions за этими методами — внутренние.

## Создание узла-документа

Узел-документ создаётся под папкой (или в корне хранилища) вместе со своим первым чекпоинтом,
атомарно. Контент rich text — документ TipTap; корневой узел должен быть `{"type":"doc", ...}`.

::: code-group

```php [PHP]
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Enums\Drive\OnNameConflict;

$node = Documents::in($drive)->create(
    name: 'Design notes',
    type: 'vaulter/rich-text',              // or 'vaulter/markdown', 'vaulter/spreadsheet'
    content: [
        'type'    => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello.'],
            ]],
        ],
    ],
    parent: $folder->id,                     // null = drive root
    options: new CreateFolderOptions(OnNameConflict::Rename),
);
```

```bash [HTTP]
# POST {prefix}/v1/drives/{drive}/documents
curl -X POST https://app.test/api/vaulter/v1/drives/42/documents \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "Design notes",
        "parent_id": null,
        "type": "vaulter/rich-text",
        "content": { "type": "doc", "content": [] }
      }'
# → 201, the Node of the new kind=document node (with its ETag)
```

```ts [Vue — client]
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node = await client.documentNodes.createNode(storageId, {
  name: 'Design notes',
  parentId: null,             // null = storage root
  format: 'richtext',
  content: { type: 'doc', content: [] },
})
```

:::

Что происходит:

- Первым отрабатывает страж уникальности среди соседей — коллизия имён бросает
  `NodeNameConflictException` или, с `OnNameConflict::Rename`, выбирает `Name (2)`.
  Незарегистрированный тип бросает `UnknownDocumentTypeException`; содержимое декодирует кодек типа.
- Одна транзакция создаёт узел и агрегат, пишет рабочее состояние (`state_version = 1`) и
  снимает чекпоинт **#1** (`is_autosave = false`), затем после коммита выбрасывает
  `DocumentCreated`.
- Узел штампуется документным mime Vaulter
  (`application/vnd.vaulter.document+json`); он представляется своим
  [типом документа](./document-types.md), а не обработчиком файла.

Запрос валидирует `name`, опциональный `parent_id`, тип документа `type` (`vendor/name`; по
умолчанию — настроенный) и опциональный `content`. Контент валидирует кодек типа
(`422 invalid_document_content`; для типа на TipTap корень должен быть `doc`), а незарегистрированный
тип отвечает `422 unknown_document_type`. Актору нужно право `Create` на целевой точке (и гейт
создания в воркспейсе драйвера прав), а профиль диска должен разрешать тип документа.

## Чтение контента

Достаньте контент **головы** узла — единственную версию, которая включает полный документ TipTap:

::: code-group

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { content, head, load, loading } = useDocumentNode(nodeId)

await load()
// content.value → TipTapDocument | null
// head.value    → the head DocumentVersion (its `content` is populated)
```

```bash [HTTP]
# GET {prefix}/v1/documents/{node}/content
curl -i https://app.test/api/vaulter/v1/documents/77/content
# → ETag: "77.0.3"
# → { "data": { "type": "vaulter/rich-text", "schema_version": 1, "state_version": 3,
#               "collab_epoch": 0, "revision_id": "12", "content": { "type": "doc", ... } } }
```

:::

В теле — `type` документа, `schema_version` контента, поколение, которому он принадлежит
(`collab_epoch` и `state_version`), `revision_id` головы и сам `content`. Ничего в нём не зависит от
того, кто читает, поэтому читатель и редактор получают одно тело и один тег:
`ETag: "{node}.{collab_epoch}.{state_version}"`. Переименование или перемещение документа тег не
меняет. `If-None-Match` с текущим тегом отвечает `304` — после того как документ найден и
авторизован, так что читатель с отозванным доступом получает `404`. Листинг истории (ниже)
опускает `content`.

## Рабочее состояние и чекпоинты

- **Автосохранение** — каждое сохранение заменяет **рабочее состояние** (без строки истории).
  **Автосохранённый чекпоинт** (`is_autosave = true`) снимается, если головы нет или с прошлого
  прошло `vaulter-documents.revisions.checkpoint_interval_seconds` (по умолчанию 300); `0` —
  чекпоинт на каждое эффективное сохранение. Сохранённые чекпоинты никогда не перезаписываются.
- **Именованный** (`is_autosave = false`, задан `label`) — устойчивый осмысленный чекпоинт
  текущего рабочего состояния, снимается по запросу, даже если ничего не менялось; с
  `keep_forever` закреплён вне прунинга.

### Автосохранение контента

Каждая запись контента идёт через `DocumentStateWriter` — единственного писателя рабочего
состояния:

- идентичное сохранение (тот же хеш контента и состояние Yjs) — **no-op**: ни роста версии, ни
  события;
- иначе `state_version` растёт ровно на единицу; передайте `expectedStateVersion`, чтобы
  отказать сохранению по устаревшему чтению (`412 document_state_mismatch`);
- замена JSON у документа с состоянием Yjs сбрасывает это состояние и двигает `collab_epoch`,
  поэтому открытые комнаты перезагружаются, а не вливают старую историю обратно.

::: code-group

```ts [Vue — дебаунс-автосохранение]
import { useDocumentNode } from '@vaulter/vue'

const { content, setContent, flush, save, saving } = useDocumentNode(nodeId, {
  debounceMs: 800,            // the CLIENT-side debounce before a save is sent
})

// As the editor emits changes, schedule a debounced save:
setContent(nextTipTapDoc)

// Force-flush a pending save (e.g. on blur / before navigation):
await flush()

// Save immediately, no debounce:
await save()
```

```bash [HTTP]
# PUT {prefix}/v1/documents/{node}/content  — 200; If-Match is required
curl -X PUT https://app.test/api/vaulter/v1/documents/77/content \
  -H 'Content-Type: application/json' \
  -H 'If-Match: "77.0.3"' \
  -d '{ "content": { "type": "doc", "content": [] } }'
```

```php [PHP]
use Vaulter\Documents\Facades\Documents;

$doc = Documents::in($drive)->update(
    $node->id,
    $tipTapDoc,
    expectedStateVersion: 3,         // optional: 412 when another save landed first
);
```

:::

PUT возвращает `200` с новым рабочим состоянием и новым `ETag`; `update()` возвращает новый
`DocumentSnapshot`. По HTTP запись требует `If-Match` с тегом контента, который вы прочитали: без
него `428 precondition_required` (как и с `*`), с устаревшим или слабым — `412 document_state_mismatch`
— например, если хаб коллаборации успел сохранить более новое состояние. Передаётся только JSON
`content`; бинарное Yjs-состояние пишет хаб. Если сохранение что-то изменило, после коммита выбрасывается
`DocumentContentUpdated`: `version` — чекпоинт-голова после сохранения, `created` — снят ли он
этим сохранением. `actorId` события — действующий пользователь (null для системы).

::: tip Клиентский дебаунс и интервал чекпоинтов
`useDocumentNode({ debounceMs })` — **клиентский** таймер перед отправкой сохранения (по умолчанию
800 мс). `revisions.checkpoint_interval_seconds` — **серверный** интервал между
автосохранёнными чекпоинтами (по умолчанию 300 с). Ключ 0.2 `revisions.autosave_debounce_seconds`
устарел и игнорируется: он перезаписывал голову на месте, чего чекпоинты никогда не делают.
:::

### Создание именованной версии

Снимите текущую голову как размеченный чекпоинт:

::: code-group

```ts [Vue]
const { createNamedVersion } = useDocumentNode(nodeId)

// keepForever = true pins it out of the prune window
const version = await createNamedVersion('Before client review', true)
```

```bash [HTTP]
# POST {prefix}/v1/documents/{node}/revisions  → 201
curl -X POST https://app.test/api/vaulter/v1/documents/77/revisions \
  -H 'Content-Type: application/json' \
  -d '{ "label": "Before client review", "keep_forever": true }'
```

```php [PHP]
$revision = Documents::in($drive)->snapshot($node->id, 'Before client review', keepForever: true);
```

:::

Именованный снимок снимает текущее **рабочее состояние** в свежую ревизию с
`is_autosave = false`, делает её головой, прунит и выбрасывает `DocumentRevisionCreated`
(`restored = false`). Ни `state_version`, ни `collab_epoch` не меняются, поэтому предусловие не
нужно.

## Листинг истории версий

`GET {prefix}/v1/documents/{node}/revisions`, а одна ревизия с контентом — это
`GET {prefix}/v1/documents/{node}/revisions/{revision}`. Закрепить ревизию или снять закрепление
можно через `PATCH {prefix}/v1/documents/{node}/revisions/{revision}` (`{ "keep_forever": true }`);
метка задаётся при создании ревизии и потом не меняется.

::: code-group

```ts [Vue]
const { versions, loadVersions } = useDocumentNode(nodeId)

await loadVersions()
// versions.value → DocumentVersion[] (newest version_no first; `content` omitted)
```

```bash [HTTP — cursor-paginated]
# GET {prefix}/v1/documents/{node}/revisions?per_page=50
curl 'https://app.test/api/vaulter/v1/documents/77/revisions?per_page=50'
```

```php [PHP]
use Vaulter\Data\Drive\ListOptions;

$page = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50));
$next = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50, cursor: $page->nextCursor));
```

:::

Листинг пагинируется курсором (`per_page` зажат в диапазон 1–200, по умолчанию 50), сначала
самые свежие (по времени создания), и опускает `content`. Каждый элемент — `DocumentRevision`; id —
строки:

```ts
interface DocumentRevision {
  id: string
  number: number
  schema_version: number
  label: string | null             // set on named revisions
  is_autosave: boolean
  keep_forever: boolean
  content_hash: string | null
  is_head: boolean
  created_by: string | null
  created_at: string
  content?: object                 // only in `GET documents/{node}/revisions/{revision}`
}
```

## Восстановление предыдущей версии

Восстановление **копирует вперёд**: исходный чекпоинт не меняется — его контент и Yjs становятся
рабочим состоянием, а свежий чекпоинт записывает `restored_from_revision_id`, поэтому история
остаётся неизменяемым аудируемым логом.

::: code-group

```ts [Vue]
const { restoreVersion } = useDocumentNode(nodeId)

const restored = await restoreVersion(versionId)
// the new head is a copy of versionId, labelled "Restored from #N"
```

```bash [HTTP]
# POST {prefix}/v1/documents/{node}/revisions/{revision}/restore  → 200; If-Match is required
curl -X POST https://app.test/api/vaulter/v1/documents/77/revisions/12/restore \
  -H 'If-Match: "77.0.3"'
```

```php [PHP]
$doc = Documents::in($drive)->restore($node->id, '12', expectedStateVersion: $doc->stateVersion);
// $doc->revision is the new head; $doc->revision->restoredFromRevisionId === '12'
```

:::

По HTTP восстановление — запись контента: нужен `If-Match` с тегом контента, ответ `200` с новым
рабочим состоянием и его тегом, а `collab_epoch` растёт.

Восстановление резолвит исходный чекпоинт **в документе этого узла** (id ревизии
другого документа или ревизии файла с тем же id здесь не восстановится), пишет его как рабочее
состояние (`state_version` +1, `collab_epoch` +1; опционально `expectedStateVersion`), снимает
чекпоинт с меткой `Restored from #N` и `restored_from_revision_id`, прунит и выбрасывает
`DocumentRevisionCreated` с `restored = true`.

## Прунинг и удержание

История ограничена `config('vaulter-documents.revisions.limit')` — числом самых свежих сохраняемых
чекпоинтов (`0` = без лимита). Прунинг выполняется **внутри** транзакции записи сразу после
каждого чекпоинта (`DocumentRevisionStoreRepository::prune`).

```php
// config/vaulter-documents.php
'revisions' => [
    'limit' => 50,                          // stored checkpoints per document before pruning (0 = ∞)
    'checkpoint_interval_seconds' => 300,   // autosave checkpoint interval described above
],
```

Прунинг **никогда** не удаляет:

- версию **`keep_forever`** — они исключаются до того, как подсчитывается окно, поэтому никогда
  не идут в зачёт `max` и никогда не удаляются; и
- **текущую голову** — всегда исключается.

Из оставшихся (прунируемых) строк сохраняются самые свежие `max`; всё за пределами этого окна
удаляется. Если `versions.max` не задан, он откатывается к легаси-ключу `max_versions`. На
PostgreSQL/MySQL голову дополнительно защищает внешний ключ головы.

::: warning Прунинг необратим
Спрунённый чекпоинт удаляется жёстко. Закрепите всё, что обязательно нужно сохранить, именованным
чекпоинтом с `keep_forever` **до** того, как он выпадет из окна `max`.
:::

## Копирование и удаление

Копирование документа — отдельного или внутри копируемой папки — создаёт **новый документ** с
рабочим состоянием источника как единственным чекпоинтом: тот же тип и версия схемы, JSON-контент
и Yjs-состояние источника, `state_version` 1, `collab_epoch` 0, первый чекпоинт не автосохранение.
История, открытые комнаты совместной работы и **комментарии не копируются**; источник не меняется.
Документ с незарегистрированным типом копируется как непрозрачный контент, тип сохраняется. Копия
не меняет `used_bytes` (встроенный контент вне квоты диска). Как и для файлов, вся копия
отклоняется с `permission_denied` до создания любых строк, если у копирующего нет `View`, `Copy`
или `Download` на любой документ поддерева.

Purge документа из корзины удаляет его чекпоинты пакетами по 500, затем строку документа, и только
потом сам узел. Без пакета documents drive удаляет документы каскадом внешних ключей и отказывается
их копировать (`unsupported_node_kind`); shortcut по-прежнему копировать нельзя.

## События

Каждая запись узла-документа выбрасывает событие после коммита (`ShouldDispatchAfterCommit`) —
привяжите к ним слушателей, ленту активности или [вебхуки](./recipes.md#webhooks):

| Событие | Кем выбрасывается | Полезная нагрузка |
|---|---|---|
| `Vaulter\Documents\Events\Documents\DocumentCreated` | создание | `node`, `version` (чекпоинт #1), `stateVersion`, `epoch`, `actorId` |
| `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | автосохранение / store коллаборации (только при изменении) | `node`, `version` (чекпоинт-голова после сохранения), `created` (bool — снят ли чекпоинт этим сохранением), `stateVersion`, `epoch` (после сохранения), `actorId` |
| `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | именование / восстановление | `node`, `version`, `stateVersion`, `epoch`, `restored` (bool), `actorId` |

## Ошибки

| Исключение | Когда |
|---|---|
| `Documents\Exceptions\NotADocumentException` | Операция с контентом/версией нацелена на узел, который не `kind=document`. |
| `Exceptions\Nodes\NodeNotFoundException` | Узла нет, он в корзине, в другом диске или тенанте, либо актор его не видит (`404 node_not_found`). |
| `Exceptions\Access\PermissionDeniedException` | Актор видит узел, но нужной способности нет (`403 permission_denied`), например экспорт без `Download`. |
| `Documents\Exceptions\UnknownDocumentTypeException` | `create()` называет неизвестный или выключенный тип (`422 unknown_document_type`). |
| `Documents\Exceptions\CommentNotFoundException` | Id комментария не принадлежит адресованному узлу (`404 comment_not_found`). |
| `Documents\Exceptions\InvalidDocumentContentException` | Корневой узел контента не `{"type":"doc"}`, либо превышает `max_document_size_kb`. |
| `Documents\Exceptions\DocumentRevisionNotFoundException` | Id ревизии не принадлежит документу узла. |
| `Documents\Exceptions\StaleDocumentStateException` | `expectedStateVersion` больше не совпадает (`412 document_state_mismatch`). |
| `Documents\Exceptions\CollaborationEpochMismatchException` | Запись коллаборации из старого поколения комнаты (`409 collaboration_epoch_mismatch`). |
| `Exceptions\Drive\NodeNameConflictException` | Создание сталкивается с именем соседа. |

## Дальше

- [Document Handlers](./document-handlers.md) — в какой поверхности редактора / превью /
  скачивания открывается узел, и добавление **нового формата без миграции**.
- [Экспорт](./export.md) — рендер контента документа в HTML / Markdown и регистрация собственного
  экспортёра PDF/DOCX.
