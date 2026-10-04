---
title: Узлы-документы
description: Создание, редактирование и версионирование узлов-документов — контент TipTap, автосохранение против именованных версий, восстановление и прунинг — поверх REST-поверхности Documents.
outline: deep
---

# Узлы-документы

**Узел-документ** — это запись с `kind=document` в едином дереве узлов (`v_nodes`), полезная
нагрузка которой — структурный TipTap JSON, версионируемый в `v_node_document_versions`. Он
живёт в том же дереве, что папки и файлы, поэтому Drive Actions (перемещение / переименование /
корзина / восстановление), а также стражи квоты и уникальности среди соседей применяются к нему
без изменений — документ это просто узел с приложенной историей версий.

Эта страница описывает создание узлов-документов, чтение и автосохранение их контента, модель
**автосохранение против именованных версий**, восстановление предыдущей версии и то, как история
**прунится** (с пинами `keep_forever`). Про слой маршрутизации по форматам — в каком редакторе
открывается узел — см. [Document Handlers](./document-handlers.md). Про рендер контента в
HTML/Markdown — см. [Экспорт](./export.md).

::: warning Фича по подписке (opt-in)
Поверхность узлов-документов включается флагом фичи **`document_versions`**, который **по
умолчанию выключен**. Таблица `v_node_document_versions` создаётся, а маршруты Documents
регистрируются, только когда он включён:

```php
// config/vaulter.php
'features' => [
    'document_versions' => true, // включает путь документов, привязанных к узлу
],
```

С выключенным флагом у `Vaulter\Documents\Models\Versions\NodeDocumentVersion` нет таблицы, а маршрутов
`storages/{storage}/documents`, `nodes/{node}/content`, `nodes/{node}/document-versions` не
существует.
:::

## Модель данных

Узел-документ несёт свой указатель на голову так же, как узел-файл — `current_version_id` на
узле указывает на головную `NodeDocumentVersion`. Таблица версий **append-only** (только
дозапись): зафиксированная версия неизменяема (нет `updated_at`, нет soft-delete), `version_no`
монотонен в пределах узла, а уникальный индекс `(node_id, version_no)` — тот стержень, на котором
сериализуются два конкурентных коммита.

Колонки `v_node_document_versions` (из `SchemaBuilder::defineDocumentVersions`):

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | id-strategy | Первичный ключ (bigint / ulid / uuid7 согласно `ids.default`). |
| `tenant_id` | nullable | Присутствует только при `tenant.enabled`. |
| `node_id` | FK → `v_nodes` | Узел-владелец с `kind=document` (каскад при удалении). |
| `version_no` | integer | Монотонен в пределах узла; `unique(node_id, version_no)`. |
| `content` | json / jsonb | Проекция TipTap/ProseMirror JSON. |
| `yjs_state` | bytea / longBlob | Бинарный Yjs CRDT-апдейт — источник истины в реальном времени (совместное редактирование). |
| `yjs_state_size` | integer, nullable | Кэшированная длина `yjs_state` в байтах (список никогда не тянет блоб, чтобы измерить его размер). |
| `format` | string(16), default `tiptap` | Ключ формата версии. |
| `content_hash` | char(64), nullable | Хэш контента (дедупликация / детекция изменений). |
| `snapshot_label` | string, nullable | Метка **именованной** версии; null для автосохранений. |
| `is_autosave` | boolean, default `false` | Отличает churn автосохранений от именованных чекпоинтов. |
| `status` | string(12), default `committed` | Статус жизненного цикла (индексирован). |
| `keep_forever` | boolean, default `false` | Пинит версию вне окна прунинга. |
| `created_by` | actor, nullable | Идентификатор актора. |
| `created_at` | timestamp, nullable | Только дозапись — `updated_at` нет. |

::: info Отличие от легаси-таблицы версий
`NodeDocumentVersion` (привязана к узлу, `v_node_document_versions`) **отдельна** от легаси
`DocumentVersion` (привязана к id документа, `v_document_versions`). Легаси-путь остаётся
нетронутым; эта страница документирует только путь, привязанный к узлу.
:::

## Создание узла-документа

Узел-документ создаётся под папкой (или в корне хранилища) вместе со своей версией **v1**,
атомарно. Контент — это документ TipTap, обёрнутый в
`Vaulter\Documents\ValueObjects\TipTapContent`; корневой узел должен быть `{"type":"doc", ...}`.

::: code-group

```php [PHP — Action]
use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Data\CreateDocumentNodeData;
use Vaulter\Documents\ValueObjects\TipTapContent;

$node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
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

Что происходит (`CreateDocumentNodeAction`):

- Первым отрабатывает страж уникальности среди соседей — коллизия имён бросает
  `NodeNameConflictException`.
- Одна транзакция создаёт узел, коммитит версию **v1** (`version_no = 1`,
  `is_autosave = false`, `status = committed`), перенаводит на неё `current_version_id`, затем
  после коммита выбрасывает `DocumentNodeCreated`.
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

На стороне сервера контроллер резолвит `head($node) ?? latest($node)` (указатель на голову,
иначе — наибольший `version_no`) и возвращает `NodeDocumentVersionResource` **с** контентом.
Листинг истории (ниже) опускает `content`, чтобы оставаться лёгким — только этот запрос одной
версии несёт полный документ.

## Автосохранение против именованных версий

У истории две полосы, и различие между ними — сердце модели версий:

- **Автосохранение** (`is_autosave = true`) — churn сессии редактирования. В пределах **окна
  дебаунса** головной автосохранённой версии новое автосохранение **перезаписывает её на месте**
  (без новой строки); за пределами окна дозаписывается свежая строка автосохранения. Это не даёт
  сессии набора текста затопить историю.
- **Именованная** (`is_autosave = false`, задан `snapshot_label`) — устойчивый, осмысленный для
  человека чекпоинт. Именованная версия **никогда** не перезаписывается путём дебаунса; она
  всегда создаёт свежую строку, а с `keep_forever` полностью пинится вне прунинга.

### Автосохранение контента

`UpdateDocumentContentAction` — это путь автосохранения. Решение перезаписать-против-дозаписать
даёт `shouldOverwrite(head)`:

- голова должна быть версией **автосохранения** (зафиксированная/именованная голова всегда
  вынуждает свежую строку), и
- она должна быть в пределах `config('vaulter.versions.autosave_debounce_seconds')` от текущего
  момента (окно `0` означает, что каждое автосохранение перезаписывает головное автосохранение).

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
use Vaulter\Data\UpdateDocumentContentData;
use Vaulter\Documents\ValueObjects\TipTapContent;

app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
    node:     $node,
    content:  TipTapContent::fromArray($tipTapDoc),
    yjsState: null,                  // optional raw Yjs binary update
    actorId:  $user->getKey(),
));
```

:::

PATCH возвращает `200`, даже когда дозаписал свежую строку — автосохранение семантически это
апдейт. `yjs_state` — это опциональный **закодированный в base64** Yjs CRDT-апдейт на проводе;
запрос декодирует его обратно в сырые байты (и он равен null для несовместного пути). TipTap
`content` — его проекция. Action после коммита выбрасывает `DocumentContentUpdated` с
`created` true/false, сообщая слушателям, была ли дозаписана строка.

::: tip Два окна дебаунса
Есть две независимые крутилки дебаунса. `useDocumentNode({ debounceMs })` — это **клиентский**
таймер перед отправкой PATCH (по умолчанию 800 мс). `versions.autosave_debounce_seconds` —
**серверное** окно, которое решает перезаписать-против-дозаписать (по умолчанию 30 с). Они не
связаны — настраивайте клиент под сетевую болтовню, а сервер — под то, насколько гранулярна
история автосохранений.
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
use Vaulter\Documents\Actions\Versions\CreateNamedVersionAction;
use Vaulter\Data\CreateNamedVersionData;

app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
    node:        $node,
    label:       'Before client review',
    keepForever: true,
    actorId:     $user->getKey(),
));
```

:::

`CreateNamedVersionAction` копирует content/yjs/format текущей головы в свежую строку с
`is_autosave = false`, перенаводит на неё голову, прунит и выбрасывает `DocumentVersionCreated`
(`restored = false`). Именованная версия превращает рабочее состояние в устойчивый чекпоинт —
в отличие от churn автосохранений.

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

Восстановление **только дозаписывает**: оно никогда не мутирует целевую версию — оно копирует её
контент в свежую версию, которая становится новой головой, поэтому история остаётся неизменяемым,
аудируемым логом, а само восстановление — версионируемым событием.

::: code-group

```ts [Vue]
const { restoreVersion } = useDocumentNode(nodeId)

const restored = await restoreVersion(versionId)
// the new head is a copy of versionId, labelled "Restored from vN"
```

```bash [HTTP]
# POST {prefix}/nodes/{node}/document-versions/{version}/restore  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions/12/restore
```

```php [PHP — Action]
use Vaulter\Documents\Actions\Versions\RestoreDocumentVersionAction;

app(RestoreDocumentVersionAction::class)->execute(
    node:      $node,
    versionId: 12,
    actorId:   $user->getKey(),
);
```

:::

`RestoreDocumentVersionAction` резолвит исходную версию **в границах узла** (id версии из другого
узла здесь никогда не восстановится), копирует её content/yjs/format в новую зафиксированную
строку с меткой `Restored from vN`, перенаводит голову, прунит и выбрасывает
`DocumentVersionCreated` с `restored = true`.

## Прунинг и удержание

История ограничена `config('vaulter.versions.max')` — числом самых свежих сохраняемых версий
(`0` = без лимита). Прунинг выполняется **внутри** каждой транзакции записи
(`NodeDocumentVersionStoreRepository::prune`) после создания/именования/восстановления.

```php
// config/vaulter.php
'versions' => [
    'max' => 50,                       // stored versions per node before pruning (0 = ∞)
    'autosave_debounce_seconds' => 30, // the server overwrite window described above
],
```

Прунинг **никогда** не удаляет:

- версию **`keep_forever`** — они исключаются до того, как подсчитывается окно, поэтому никогда
  не идут в зачёт `max` и никогда не удаляются; и
- **текущую голову** — всегда исключается.

Из оставшихся (прунируемых) строк сохраняются самые свежие `max`; всё за пределами этого окна
удаляется. Если `versions.max` не задан, он откатывается к легаси-ключу `max_versions`.

::: warning Прунинг необратим
Спрунённая версия удаляется жёстко (таблица append-only, без soft-delete). Прикрепите всё, что
обязательно нужно сохранить, именованной версией с `keep_forever` **до** того, как она выпадет из
окна `max`.
:::

## События

Каждая запись узла-документа выбрасывает событие после коммита (`ShouldDispatchAfterCommit`) —
привяжите к ним слушателей, ленту активности или [вебхуки](./recipes.md#webhooks):

| Событие | Кем выбрасывается | Полезная нагрузка |
|---|---|---|
| `Vaulter\Documents\Events\Documents\DocumentNodeCreated` | создание | `node`, `version` (v1), `actorId` |
| `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | автосохранение | `node`, `version`, `created` (bool — дозаписано против перезаписано), `actorId` |
| `Vaulter\Documents\Events\Versions\DocumentVersionCreated` | именование / восстановление | `node`, `version`, `restored` (bool), `actorId` |

## Ошибки

| Исключение | Когда |
|---|---|
| `Exceptions\Documents\NotADocumentNodeException` | Операция с контентом/версией нацелена на узел, который не `kind=document`. |
| `Documents\Exceptions\InvalidTipTapContentException` | Корневой узел контента не `{"type":"doc"}`, либо превышает `max_document_size_kb`. |
| `Exceptions\Documents\NodeDocumentVersionNotFoundException` | Восстановление нацелено на id версии, не принадлежащий узлу. |
| `Exceptions\Drive\NodeNameConflictException` | Создание сталкивается с именем соседа. |

## Дальше

- [Document Handlers](./document-handlers.md) — в какой поверхности редактора / превью /
  скачивания открывается узел, и добавление **нового формата без миграции**.
- [Экспорт](./export.md) — рендер контента документа в HTML / Markdown и регистрация собственного
  экспортёра PDF/DOCX.
