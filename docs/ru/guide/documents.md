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

## Создание узла-документа

Узел-документ создаётся под папкой (или в корне хранилища) вместе со своей версией **v1**,
атомарно. Контент — это документ TipTap, обёрнутый в
`Vaulter\Documents\ValueObjects\TipTapContent`; корневой узел должен быть `{"type":"doc", ...}`.

::: code-group

```php [PHP — Action]
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\ValueObjects\TipTapContent;

$node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
    storage: $storage,                       // the resolved v_storages row
    name:    'Design notes',
    content: TipTapContent::fromArray([
        'type'    => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello.'],
            ]],
        ],
    ]),
    parent:  $folder,                        // null = storage root
    format:  'richtext',                     // a DocumentType value (default 'tiptap')
    actorId: $user->getKey(),
));
```

```bash [HTTP]
# POST {prefix}/storages/{storage}/documents
curl -X POST https://app.test/api/vaulter/storages/42/documents \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "Design notes",
        "parent_id": null,
        "format": "richtext",
        "content": { "type": "doc", "content": [] }
      }'
# → 201, a NodeResource for the new kind=document node
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

Что происходит (`CreateDocumentAction`):

- Первым отрабатывает страж уникальности среди соседей — коллизия имён бросает
  `NodeNameConflictException`.
- Одна транзакция создаёт узел и агрегат, пишет рабочее состояние (`state_version = 1`) и
  снимает чекпоинт **#1** (`is_autosave = false`), затем после коммита выбрасывает
  `DocumentCreated`.
- Узел штампуется документным mime Vaulter
  (`application/vnd.vaulter.document+json`), поэтому он всегда маршрутизируется к обработчику
  редактора TipTap независимо от любого расширения в его имени. См.
  [Document Handlers](./document-handlers.md).

Запрос валидирует `name`, опциональный `parent_id`, опциональный `format` (должен быть
значением [`DocumentType`](./document-handlers.md#documenttype): `richtext`, `table`, `markdown`,
`template`) и опциональный `content`, у которого `content.type` должен быть `doc`. Авторизация
проверяется выше по потоку в контроллере — актору нужны и `canCreateInWorkspace`, и право
(ability) `Create` на целевой точке.

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
# GET {prefix}/nodes/{node}/content
curl https://app.test/api/vaulter/nodes/77/content
# → { "data": { ...version, "content": { "type": "doc", ... } } }
```

:::

Ответ сохраняет форму версии: `id` и `version_no` называют чекпоинт-голову, а
`content`/`content_hash`/`yjs_state_size` — это рабочее состояние; `is_autosave` равен `true`,
пока рабочее состояние опережает голову. Листинг истории (ниже) опускает `content`.

## Рабочее состояние и чекпоинты

- **Автосохранение** — каждое сохранение заменяет **рабочее состояние** (без строки истории).
  **Автосохранённый чекпоинт** (`is_autosave = true`) снимается, если головы нет или с прошлого
  прошло `vaulter-documents.revisions.checkpoint_interval_seconds` (по умолчанию 300); `0` —
  чекпоинт на каждое эффективное сохранение. Сохранённые чекпоинты никогда не перезаписываются.
- **Именованный** (`is_autosave = false`, задан `label`) — устойчивый осмысленный чекпоинт
  текущего рабочего состояния, снимается по запросу, даже если ничего не менялось; с
  `keep_forever` закреплён вне прунинга.

### Автосохранение контента

`UpdateDocumentContentAction` пишет через `DocumentStateWriter` — единственного писателя
рабочего состояния:

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
  debounceMs: 800,            // the CLIENT-side debounce before a PATCH is sent
})

// As the editor emits changes, schedule a debounced save:
setContent(nextTipTapDoc)

// Force-flush a pending save (e.g. on blur / before navigation):
await flush()

// Save immediately, no debounce:
await save()
```

```bash [HTTP]
# PATCH {prefix}/nodes/{node}/content  — always 200 (semantically an UPDATE)
curl -X PATCH https://app.test/api/vaulter/nodes/77/content \
  -H 'Content-Type: application/json' \
  -d '{
        "content": { "type": "doc", "content": [] },
        "yjs_state": null
      }'
```

```php [PHP — Action]
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\ValueObjects\TipTapContent;

app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
    node:     $node,
    content:  TipTapContent::fromArray($tipTapDoc),
    yjsState: null,                  // optional raw Yjs binary update
    actorId:  $user->getKey(),
    expectedStateVersion: 3,         // optional: 412 when another save landed first
));
```

:::

PATCH возвращает `200` с рабочим состоянием — автосохранение семантически это апдейт. `yjs_state` — это опциональный **закодированный в base64** Yjs CRDT-апдейт на проводе;
запрос декодирует его обратно в сырые байты (и он равен null для несовместного пути). TipTap
`content` — его проекция. Если сохранение что-то изменило, Action после коммита выбрасывает
`DocumentContentUpdated`: `version` — чекпоинт-голова после сохранения, `created` — снят ли он
этим сохранением.

::: tip Клиентский дебаунс и интервал чекпоинтов
`useDocumentNode({ debounceMs })` — **клиентский** таймер перед отправкой PATCH (по умолчанию
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
# POST {prefix}/nodes/{node}/document-versions  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions \
  -H 'Content-Type: application/json' \
  -d '{ "label": "Before client review", "keep_forever": true }'
```

```php [PHP — Action]
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;

app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(
    node:        $node,
    label:       'Before client review',
    keepForever: true,
    actorId:     $user->getKey(),
));
```

:::

`CreateNamedRevisionAction` снимает текущее **рабочее состояние** в свежую ревизию с
`is_autosave = false`, делает её головой, прунит и выбрасывает `DocumentRevisionCreated`
(`restored = false`). Ни `state_version`, ни `collab_epoch` не меняются, поэтому предусловие не
нужно.

## Листинг истории версий

::: code-group

```ts [Vue]
const { versions, loadVersions } = useDocumentNode(nodeId)

await loadVersions()
// versions.value → DocumentVersion[] (newest version_no first; `content` omitted)
```

```bash [HTTP — cursor-paginated]
# GET {prefix}/nodes/{node}/document-versions?per_page=50
curl 'https://app.test/api/vaulter/nodes/77/document-versions?per_page=50'
```

:::

Листинг пагинируется курсором (`per_page` зажат в диапазон 1–200, по умолчанию 50), сначала
самый свежий `version_no`, и опускает `content`. Каждый элемент — `DocumentVersion`:

```ts
interface DocumentVersion {
  id: NodeId
  node_id: NodeId
  version_no: number
  format: 'richtext' | 'table' | 'markdown' | string
  snapshot_label: string | null   // set on named versions
  is_autosave: boolean
  keep_forever: boolean
  status: string                   // 'committed'
  content_hash: string | null
  yjs_state_size: number | null    // the binary state is NOT serialised, only its size
  created_by: WorkspaceId | null
  created_at: string | null
  content: TipTapDocument | null   // populated only by the single-version `content` fetch
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
# POST {prefix}/nodes/{node}/document-versions/{version}/restore  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions/12/restore
```

```php [PHP — Action]
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;

app(RestoreDocumentRevisionAction::class)->execute(
    node:      $node,
    versionId: 12,
    actorId:   $user->getKey(),
);
```

:::

`RestoreDocumentRevisionAction` резолвит исходный чекпоинт **в документе этого узла** (id ревизии
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

## События

Каждая запись узла-документа выбрасывает событие после коммита (`ShouldDispatchAfterCommit`) —
привяжите к ним слушателей, ленту активности или [вебхуки](./recipes.md#webhooks):

| Событие | Кем выбрасывается | Полезная нагрузка |
|---|---|---|
| `Vaulter\Documents\Events\Documents\DocumentCreated` | создание | `node`, `version` (чекпоинт #1), `actorId` |
| `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | автосохранение / store коллаборации (только при изменении) | `node`, `version` (чекпоинт-голова после сохранения), `created` (bool — снят ли чекпоинт этим сохранением), `actorId` |
| `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | именование / восстановление | `node`, `version`, `restored` (bool), `actorId` |

## Ошибки

| Исключение | Когда |
|---|---|
| `Exceptions\NotADocumentException` | Операция с контентом/версией нацелена на узел, который не `kind=document`. |
| `Documents\Exceptions\InvalidDocumentContentException` | Корневой узел контента не `{"type":"doc"}`, либо превышает `max_document_size_kb`. |
| `Exceptions\DocumentRevisionNotFoundException` | Восстановление нацелено на id версии, не принадлежащий узлу. |
| `Documents\Exceptions\StaleDocumentStateException` | `expectedStateVersion` больше не совпадает (`412 document_state_mismatch`). |
| `Documents\Exceptions\CollaborationEpochMismatchException` | Запись коллаборации из старого поколения комнаты (`409 collaboration_epoch_mismatch`). |
| `Exceptions\Drive\NodeNameConflictException` | Создание сталкивается с именем соседа. |

## Дальше

- [Document Handlers](./document-handlers.md) — в какой поверхности редактора / превью /
  скачивания открывается узел, и добавление **нового формата без миграции**.
- [Экспорт](./export.md) — рендер контента документа в HTML / Markdown и регистрация собственного
  экспортёра PDF/DOCX.
