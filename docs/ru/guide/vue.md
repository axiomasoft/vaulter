---
title: Vue-пакет (@vaulter/vue)
description: UI Vaulter Drive для Vue 3 — типизированный API-клиент, composable-функции Drive (useNodes, useUpload, useNodeGrants, useNodeShares, useDocumentNode, useNodeCollaboration) и компоненты (VaulterDrive, breadcrumb, list, uploader, панели grants/share, документный узел), с разобранным примером файлового менеджера.
outline: deep
---

# Vue-пакет (`@vaulter/vue`)

`@vaulter/vue` — пакет на Vue 3 + TypeScript, который поставляет три слоя:

1. **типизированный API-клиент** (`createVaulterClient`), который общается с REST API Vaulter;
2. **composable-функции**, оборачивающие клиент в реактивное состояние (`useNodes`, `useUpload`, …);
3. **компоненты**, собирающие composable-функции в headless-UI Drive (`VaulterDrive`, …).

Всё аддитивно: новая поверхность **Drive** (дерево файлов/папок/документов) живёт рядом с
легаси-компонентами, ориентированными на документы. Эта страница описывает поверхность Drive — ту,
на которой вы строите новые приложения.

```bash
npm install @vaulter/vue
```

::: tip Peer-зависимость
`@vaulter/vue` объявляет `vue@^3.4` как peer-зависимость. Транспорт совместного редактирования
дополнительно тянет `@hocuspocus/provider`, `@tiptap/*` и `yjs` (уже объявлены как зависимости).
:::

## Настройка

### Как плагин

Установите плагин один раз: он создаёт клиент из ваших опций и предоставляет его всему приложению, а
также регистрирует каждый компонент глобально:

```ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter',                  // default
    csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content,
  })
  .mount('#app')
```

`VaulterPluginOptions` расширяет `VaulterClientOptions` (ниже) и добавляет одно поле:

| Опция | Тип | Примечания |
|--------|------|-------|
| `client` | `VaulterClient` | передать заранее собранный клиент вместо конструирования из опций (тесты / кастомная проводка) |

### Передача заранее собранного клиента

Для тестов или пораундовой SSR-проводки соберите клиент сами и предоставьте его:

```ts
import { createVaulterClient, provideVaulterClient } from '@vaulter/vue'

const client = createVaulterClient({ baseUrl: '/api/vaulter' })
provideVaulterClient(app, client)
```

В любом дочернем компоненте разрешите его через `useVaulterClient()`. Он бросит понятную ошибку, если
клиент не был предоставлен — установите плагин или вызовите `provideVaulterClient()` в родителе.

### Опции клиента

`createVaulterClient(options)` и плагин оба принимают `VaulterClientOptions`:

| Опция | Тип | По умолчанию | Примечания |
|--------|------|---------|-------|
| `baseUrl` | `string` | `/api/vaulter` | соответствует конфигу `routes.prefix` на бэкенде. Хвостовые слэши обрезаются |
| `headers` | `Record<string, string>` или `() => Record<string, string>` | — | дополнительные заголовки запроса или геттер (например, ротируемый bearer-токен) |
| `csrfToken` | `string` | — | отправляется как `X-CSRF-TOKEN` |
| `fetch` | `typeof fetch` | глобальный `fetch` | своя реализация fetch |

Клиент отправляет `credentials: 'same-origin'` и `Accept: application/json`; тела — JSON, если только
вызов не загружает `FormData` или не стримит чанк tus/S3.

::: warning Ошибки
Ответ не из диапазона 2xx бросает `VaulterApiError` с полями `status`, `message`, `errors`
(`Record<string, string[]>`, форма валидации Laravel) и сырым `Response`. Ловите его, чтобы отрисовать
ошибки валидации или 403.
:::

## API-клиент

`createVaulterClient()` возвращает `VaulterClient`, чьи методы сгруппированы в **пространства имён**.
Пространства имён Drive (M1–M4):

| Пространство имён | Что покрывает | Опорные маршруты (под `baseUrl`) |
|-----------|--------|----------------------------------|
| `storages` | одно хранилище-контейнер + его корень/использование | `GET /storages/{storage}` |
| `nodes` | CRUD дерева папок/файлов/документов + поиск + корзина + скачивание | `/storages/{storage}/nodes`, `/nodes/{node}/*` |
| `uploads` | прямые + резюмируемые (tus / S3-multipart) загрузки | `/storages/{storage}/uploads`, `/resumable-uploads/*` |
| `grants` | гранты ACL на узел | `/nodes/{node}/grants` |
| `nodeShares` | bearer-ссылки share + публичный resolve/download | `/nodes/{node}/shares`, `/share/{token}*` |
| `activity` | append-only лента активности | `/storages/{storage}/activity`, `/nodes/{node}/activity` |
| `documentNodes` | контент документного узла, версии, обработчик, экспорт | `/storages/{storage}/documents`, `/nodes/{node}/content`, `/nodes/{node}/document-versions/*` |
| `nodeCollaboration` | рукопожатие сессии realtime-совместного редактирования | `POST /nodes/{node}/collab/token` |

Легаси-пространства имён, ориентированные на документы (`documents`, `versions`, `folders`, `permissions`,
`comments`, `shares`, `collaboration`), остаются для хостов, всё ещё сидящих на той поверхности.

```ts
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const storage = await client.storages.get(storageId)
const page    = await client.nodes.list(storageId, { parentId: null }) // CursorPage<Node>
const folder  = await client.nodes.createFolder(storageId, { name: 'Invoices' })
const node    = await client.uploads.upload(storageId, file, { parentId: folder.id })
```

Эндпоинты списков используют **курсорную пагинацию**: `client.nodes.list(...)` резолвит `CursorPage<Node>`
(`{ data, meta }`); читайте `meta.next_cursor` и передавайте его обратно как `{ cursor }` для следующей
страницы.

## Composable-функции

Все composable-функции Drive внутри вызывают `useVaulterClient()`, поэтому они должны исполняться внутри
компонента, который находится под предоставленным клиентом. Каждая выдаёт реактивные ref-ы плюс
асинхронные методы, которые мутируют их оптимистично.

### `useNodes`

Реактивный листинг дочерних узлов папки с навигацией и мутациями узлов, завязанными на клиент.

```ts
import { useNodes } from '@vaulter/vue'

const {
  nodes, parentId, cursor, loading, error,
  refresh, loadMore, navigate,
  createFolder, rename, move, copy, trash, restore, forceDelete,
} = useNodes(storageId, () => currentFolderId.value)
```

| Член | Тип | Примечания |
|--------|------|-------|
| `nodes` | `ShallowRef<Node[]>` | дочерние узлы текущей папки |
| `parentId` | `Ref<NodeId \| null>` | листаемая папка (`null` = корень хранилища) |
| `cursor` | `Ref<CursorMeta \| null>` | мета пагинации последней загрузки |
| `loading` / `error` | `Ref<boolean>` / `Ref<unknown>` | состояние запроса |
| `refresh()` | `Promise<Node[]>` | (пере)загрузить первую страницу текущей папки |
| `loadMore()` | `Promise<Node[]>` | добавить следующую курсорную страницу (no-op, когда её нет) |
| `navigate(parentId)` | `Promise<Node[]>` | войти в папку (`null` = корень) и загрузить её |
| `createFolder(payload)` | `Promise<Node>` | создать папку (отражается в списке, когда она попадает сюда) |
| `rename` / `move` / `copy` / `trash` / `restore` / `forceDelete` | `Promise<Node \| void>` | мутации узла — список обновляется оптимистично (rename оставляет строку, move/trash убирают её, copy/restore дописывают) |

Передайте `parentRef` (ref или геттер), чтобы управлять навигацией снаружи — когда он меняется, список
перезагружается.

### `useSharedNodes`

Реактивный листинг **«поделились со мной»** поверх кросс-хранилищного эндпоинта `GET /shared` — узлы, на
которые у актора есть живой разрешающий грант (перерезолвится точно на стороне сервера). **Плоский**
список: здесь нет `parentId` / `navigate`, потому что набор охватывает несколько хранилищ. Эндпоинт
**знает длину** (`page` / `total`), поэтому пагинация — на основе `page`.

```ts
import { useSharedNodes } from '@vaulter/vue'

const { nodes, meta, loading, error, hasMore, refresh, loadMore } = useSharedNodes()
```

| Член | Тип | Примечания |
|--------|------|-------|
| `nodes` | `ShallowRef<Node[]>` | узлы, которыми поделились (плоский список, кросс-хранилищный) |
| `meta` | `ShallowRef<PageMeta \| null>` | счётчики страниц + total последней загрузки |
| `loading` / `error` | `Ref<boolean>` / `Ref<unknown>` | состояние запроса |
| `hasMore` | `Ref<boolean>` | существует ли ещё одна страница |
| `refresh()` | `Promise<Node[]>` | (пере)загрузить первую страницу, заменив список |
| `loadMore()` | `Promise<Node[]>` | добавить следующую страницу (no-op, когда её нет) |

Он **не** загружается автоматически — вызовите `refresh()` на монтировании (идиома, которую разделяют
composable-функции Drive). Передайте опциональный `SharedNodesQuery` (`{ page, perPage }`), чтобы задать
начальную страницу / размер.

### `useUpload`

Загрузка в хранилище с прогрессом; выставляет и прямой, и резюмируемый (tus) пути.

```ts
import { useUpload } from '@vaulter/vue'

const { uploading, error, sent, total, progress, upload, uploadResumable, abort } =
  useUpload(storageId)

await upload(file, { parentId })            // single-request
await uploadResumable(largeFile, {          // chunked, resumable, progress-reporting
  parentId,
  onProgress: (sent, total) => { /* … */ },
})
```

| Член | Тип | Примечания |
|--------|------|-------|
| `uploading` / `error` | `Ref<boolean>` / `Ref<unknown>` | состояние запроса |
| `sent` / `total` | `Ref<number>` | байты активной резюмируемой загрузки |
| `progress` | `ComputedRef<number>` | `sent / total`, `0` в простое |
| `upload(file, options?)` | `Promise<Node>` | прямая загрузка одним запросом |
| `uploadResumable(file, options?)` | `Promise<Node>` | ведёт tus-сессию, возобновляясь с **серверного** смещения |
| `abort()` | `Promise<void>` | прервать активную резюмируемую загрузку |

`useUpload` принимает опциональный второй аргумент — заранее разрешённый клиент — чтобы поднять пофайловый
загрузчик **вне** контекста инъекции `setup()` (например, по одному экземпляру на брошенный файл), где
`inject()` уже недоступен. `VaulterUploader` использует ровно этот паттерн.

### `useNodeGrants`

Реактивное управление грантами ACL на узел.

```ts
import { useNodeGrants } from '@vaulter/vue'

const { grants, loading, error, list, grant, revoke } = useNodeGrants(nodeId)

await list()
await grant({ subjectType: 'user', subjectId: 42, role: 'writer', scope: 'subtree' })
await revoke({ subjectType: 'user', subjectId: 42 })
```

| Член | Тип | Примечания |
|--------|------|-------|
| `grants` | `ShallowRef<NodeGrant[]>` | гранты узла |
| `list()` | `Promise<NodeGrant[]>` | загрузить гранты |
| `grant(payload)` | `Promise<NodeGrant>` | добавить грант (добавляется в начало списка) |
| `revoke(payload)` | `Promise<void>` | снять **все** гранты, что держит субъект |

Полезная нагрузка гранта (`GrantNodeAccessPayload`) несёт `subjectType`, `subjectId`, `role`
(`previewer` / `reader` / `commenter` / `writer` / `organizer` / `owner`) и опциональные `scope`
(`item` / `subtree`) и `expiresAt`.

### `useNodeShares`

Реактивное управление **bearer-ссылками share** узла. Сырой токен всплывает **ровно один раз** через
`lastCreated`; перечисленные ссылки несут только `token_prefix`.

```ts
import { useNodeShares } from '@vaulter/vue'

const { shares, lastCreated, loading, error, list, create, revoke, clearLastCreated } =
  useNodeShares(nodeId)

const created = await create({ level: 'reader', scope: 'item', requireLogin: true })
// created.token + created.url доступны ЗДЕСЬ — и только здесь.
clearLastCreated() // забыть секрет, чтобы он никогда не задерживался в памяти
```

| Член | Тип | Примечания |
|--------|------|-------|
| `shares` | `ShallowRef<NodeShare[]>` | ссылки узла (без сырого токена) |
| `lastCreated` | `ShallowRef<NodeShareCreated \| null>` | последний выпущенный сырой токен + URL, хранится один раз |
| `create(payload?)` | `Promise<NodeShareCreated>` | выпустить ссылку; ответ несёт `token` + `url` один раз |
| `revoke(shareId)` | `Promise<void>` | отозвать ссылку |
| `clearLastCreated()` | `void` | забыть последний выпущенный секрет |

### `useDocumentNode`

Реактивное редактирование **документного узла**: загрузка head-контента, правка с автосохранением по
дебаунсу, снапшот/восстановление именованных версий, резолв обработчика и экспорт.

```ts
import { useDocumentNode } from '@vaulter/vue'

const {
  content, head, versions, handler, loading, saving, error,
  load, setContent, flush, save,
  loadVersions, createNamedVersion, restoreVersion, loadHandler, exportAs,
} = useDocumentNode(nodeId, { debounceMs: 800 })

await load()                                  // fetch the head content
setContent(newTipTapDoc)                       // schedules a debounced autosave
await flush()                                  // force any pending autosave now
await createNamedVersion('Before edit', true)  // snapshot, keep forever
```

| Член | Тип | Примечания |
|--------|------|-------|
| `content` | `ShallowRef<TipTapDocument \| null>` | рабочий контент |
| `head` | `ShallowRef<DocumentVersion \| null>` | текущая head-версия |
| `versions` | `ShallowRef<DocumentVersion[]>` | загруженные именованные/авто версии |
| `handler` | `ShallowRef<NodePresentation \| null>` | разрешённый обработчик документа |
| `saving` | `Ref<boolean>` | true, пока сохранение в полёте |
| `setContent(content, options?)` | `void` | задать рабочий контент + запланировать автосохранение по дебаунсу |
| `flush()` | `Promise<DocumentVersion \| null>` | форс-сброс отложенного автосохранения (null, когда ничего не отложено) |
| `save(options?)` | `Promise<DocumentVersion>` | сохранить немедленно (без дебаунса) |
| `createNamedVersion(label, keepForever?)` | `Promise<DocumentVersion>` | снапшот именованной версии |
| `restoreVersion(versionId)` | `Promise<DocumentVersion>` | восстановить версию (обновляет head + content) |
| `exportAs(format?)` | `Promise<NodeDocumentExport>` | экспортировать документ узла |

`options.yjsState` у `setContent` несёт опциональный base64-апдейт Yjs CRDT (realtime-источник истины) —
передавайте его, когда мостите из Yjs-редактора.

### `useNodeCollaboration`

Открывает сессию realtime-совместного редактирования для документного узла. Он забирает токен комнаты,
затем строит поверх него Yjs-сессию (Hocuspocus через `@hocuspocus/provider`, Reverb через мост,
поставляемый хостом).

```ts
import { useNodeCollaboration } from '@vaulter/vue'

const {
  doc, session, handle,
  connecting, connected, readOnly, collaborators, error,
  fetchSession, connect, disconnect,
} = useNodeCollaboration(nodeId)

await connect()        // mints the token AND opens the Yjs session
// bind `doc` to TipTap via the Collaboration extension; mount read-only when readOnly is true
onBeforeUnmount(disconnect)
```

| Член | Тип | Примечания |
|--------|------|-------|
| `doc` | `Y.Doc` | общий Yjs-документ для привязки к TipTap |
| `session` | `ShallowRef<NodeCollaborationSession \| null>` | разрешённый дескриптор бэкенда |
| `connected` | `Ref<boolean>` | флаг живого соединения |
| `readOnly` | `Ref<boolean>` | запись запрещена (из бэкенд-права актора) |
| `collaborators` | `Ref<Collaborator[]>` | удалённые участники (awareness) |
| `fetchSession()` | `Promise<NodeCollaborationSession>` | забрать только токен (стройте свой собственный provider) |
| `connect()` | `Promise<NodeCollaborationSessionHandle>` | забрать токен И открыть сессию |
| `disconnect()` | `void` | снести живую сессию (идемпотентно) |

Про детали транспорта см. [Совместное редактирование в реальном времени](/ru/guide/collaboration).

::: details Прочие composable-функции
Пакет также экспортирует `useStorage` (загрузить одно хранилище), `useActivity` (лента активности),
`useComments` (ветка комментариев узла), `useNodeGrants` / `useNodeShares` (ACL узла и share-ссылки)
и `useSpreadsheet` (безголовая оболочка книги). Полное покрытие живёт в
[Vue composables](/ru/guide/vue).
:::

## Компоненты

Все компоненты **почти-headless**: минимальные scoped-стили, class-хуки (`vaulter-*`) и слоты повсюду, так
что вы можете полностью их перестилизовать.

### `VaulterDrive`

Заглавный файловый менеджер: тулбар (новая папка, загрузка, переключатель список/сетка, поиск), breadcrumb
и листинг узлов. Открытие папки навигирует внутренне; открытие файла/документа эмитит `open-node` для
хоста. Действия в строке завязаны на `useNodes`, с гейтом подтверждения на разрушающих.

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | обязательный |
| `initialParentId` | `NodeId \| null` | `null` | папка, которую открыть изначально (`null` = корень) |
| `viewMode` | `'list' \| 'grid'` | `'list'` | начальный вид |
| `actions` | `NodeAction[]` | `['rename','move','trash','download','share']` | действия в строке для предложения (всё равно фильтруются по правам узла) |
| `confirm` | `(message) => boolean \| Promise<boolean>` | `window.confirm` | гейт для разрушающих действий |

**Эмиты**

| Событие | Полезная нагрузка | Когда |
|-------|---------|------|
| `open-node` | `node: Node` | открыт непапочный узел — хост открывает/редактирует/превьюит его |
| `node-selected` | `node: Node` | узел выбран (одиночный клик) |
| `navigate` | `parentId: NodeId \| null` | листаемая папка сменилась |

**Слоты**: `toolbar` (с `new-folder`, `view`, `set-view`, `search-term`), `error`, `empty`.
**Экспоузит**: `refresh`, `goTo`, `nodes`, `displayNodes`, `selectedId`, `view`.

### `VaulterDriveBreadcrumb`

Родословная корень → текущий узел в виде кликабельных крошек. Навигация **только-эмит** — хост решает, что
делает `navigate(null = корень)`.

| Проп | Тип | По умолчанию |
|------|------|---------|
| `path` | `Node[]` | `[]` (breadcrumb узла) |
| `rootLabel` | `string` | `'Drive'` |

Эмитит `navigate: NodeId \| null`. Слоты: `root`, `crumb` (на каждого предка, с `node`, `is-current`).

### `VaulterNodeList`

Листинг узлов — список или сетка узлов с иконкой по kind/mime, именем, размером и датой изменения.
Действия в строке гейтятся на `node.abilities` (действие, которое актор не может выполнить, никогда не
предлагается).

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `nodes` | `Node[]` | — | обязательный |
| `viewMode` | `'list' \| 'grid'` | `'list'` | |
| `selectedId` | `NodeId \| null` | `null` | подсветка одиночного выбора |
| `actions` | `NodeAction[]` | `['rename','move','trash','download','share']` | предлагаемый набор ∩ права каждого узла |
| `loading` | `boolean` | `false` | рендерит слот `loading` |

`NodeAction` — это `'rename' \| 'move' \| 'copy' \| 'trash' \| 'restore' \| 'forceDelete' \| 'download' \| 'share'`.

**Эмиты**: `select(node)`, `open(node)` (двойной клик / Enter), `action(action, node)`.
**Слоты**: `loading`, `empty`, `icon` (с `node`, `kind`), `name`, `actions` (с `node`, `actions`, `run`).

### `VaulterUploader`

Автономная зона загрузки drag-drop + выбор файла, ведущая `useUpload`. Мелкие файлы идут напрямую; файлы
сверх `resumableThreshold` используют резюмируемый (tus) путь с побайтовым прогрессом и отменой. Каждый файл
получает **свой** `useUpload`, так что прогресс отслеживается независимо.

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | обязательный |
| `parentId` | `NodeId \| null` | `null` | папка, в которую грузить (`null` = корень) |
| `resumableThreshold` | `number` | `5 * 1024 * 1024` | байты, выше которых используется tus-путь |
| `accept` | `string` | — | атрибут `accept` файлового инпута |
| `multiple` | `boolean` | `true` | разрешить выбор более одного файла |
| `disabled` | `boolean` | `false` | |

**Эмиты**: `uploaded(node)`, `error(file, error)`.
**Слоты**: `dropzone` (с `open-picker`, `is-dragging`), `item` (с `item`, `progress`, `cancel`).
**Экспоузит**: `openPicker`, `items`, `clearFinished`.

### `VaulterNodeGrantsPanel`

Управление ACL на узел поверх `useNodeGrants` — список грантов, добавление гранта, отзыв. Хост владеет
**выбором субъекта** (компонент не может знать ваших пользователей/роли/команды), делегированным в слот
`subject` или проп `resolveSubject`.

**Пропсы**

| Проп | Тип | По умолчанию |
|------|------|---------|
| `nodeId` | `NodeId` | — |
| `autoLoad` | `boolean` | `true` |
| `roles` | `GrantRoleName[]` | `['previewer','reader','commenter','writer','organizer','owner']` |
| `scopes` | `GrantScopeName[]` | `['item','subtree']` |
| `resolveSubject` | `(type, id) => GrantSubject \| Promise<GrantSubject>` | пробрасывает сырые type/id как есть |

`GrantSubject` — это `{ type: string; id: WorkspaceId; label?: string }`.
**Эмиты**: `granted(grant)`, `revoked(subject)`.
**Слоты**: `loading`, `item` (с `grant`, `revoke`), `subject` (с `set-subject`, `subject-type`,
`subject-id`), `error`.

### `VaulterNodeSharePanel`

Управление bearer-ссылками share поверх `useNodeShares`. При создании сырой токен + URL всплывают
**ровно один раз** (через `lastCreated`) с кнопкой копирования; перечисленные ссылки несут только
`token_prefix`.

**Пропсы**

| Проп | Тип | По умолчанию |
|------|------|---------|
| `nodeId` | `NodeId` | — |
| `autoLoad` | `boolean` | `true` |
| `levels` | `GrantLevelName[]` | `['previewer','reader','writer']` |
| `scopes` | `GrantScopeName[]` | `['item','subtree']` |

**Эмиты**: `created(share)`, `revoked(shareId)`.
**Слоты**: `created` (с `share`, `url`, `copy`, `dismiss`, `copied`), `loading`, `item`, `error`.

### `VaulterDocumentNode`

Мост от **дескриптора обработчика** документного узла к конкретному UI редактора/превью. Он загружает
обработчик (`useDocumentNode.loadHandler`) и head-контент, затем монтирует компонент, на который
отображается `ui.component` обработчика — отображение задаёт ваш проп `componentMap`, так что хосты
регистрируют собственные редакторы под каждый обработчик. Неотображённые обработчики откатываются к слоту
превью/скачивания.

Когда обработчик **совместный** и совместное редактирование включено, он открывает Yjs-сессию
(`useNodeCollaboration`) и отдаёт общий `doc` редактору (привяжите его через расширение TipTap
Collaboration); путь автосохранения обходится.

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `nodeId` | `NodeId` | — | обязательный |
| `componentMap` | `Record<string, Component>` | `{}` | отображает `ui.component` обработчика (или `id`) на компонент-редактор |
| `readonly` | `boolean` | `false` | форсировать только-чтение |
| `autoLoad` | `boolean` | `true` | загрузка на монтировании |
| `debounceMs` | `number` | `800` | дебаунс автосохранения |
| `collaboration` | `boolean` | `true` | включить realtime-совместное редактирование, когда обработчик его объявляет |
| `collaborationProviders` | `Partial<…>` | `{}` | фабрики provider-ов (переопределение Hocuspocus / мост Reverb) |

Ключи TipTap-редактора `VaulterEditor`, `tiptap`, `richtext`, `rich-text` резолвятся в поставляемый хостом
редактор под одним из этих ключей `componentMap`.

**Эмиты**: `ready(handler)`, `saved(content)`, `collaborating(readonly)`.
**Слоты**: дефолтный слот (с `handler`, `content`, `readonly`, `set-content`, `flush`, `collaborative`,
`ydoc`, `connected`, `collaborators`), плюс `loading`, `fallback`, `error`.

Тип `DocumentNodeEditorProps` описывает, что получает отображённый компонент-редактор — `nodeId`,
`content`, `handler`, `readonly`, `onChange` и (в совместном режиме) `collaborative`, `ydoc`, `connected`,
`collaborators`.

### `VaulterSharedWithMe`

Кросс-хранилищный вид **«поделились со мной»** поверх [`useSharedNodes`](#usesharednodes): **плоский**
листинг узлов, которыми поделились с актором, без навигации по папкам (узлы охватывают несколько хранилищ,
поэтому каждое открытие эмитит `open-node`, чтобы хост его смаршрутизировал). Он переиспользует
`VaulterNodeList` для рендеринга и владеет только обвязкой загрузки / обновления / пустого / ошибки плюс
пейджером «Загрузить ещё».

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `viewMode` | `'list' \| 'grid'` | `'list'` | |
| `actions` | `NodeAction[]` | `['download','share']` | действия в строке для предложения (всё равно фильтруются по правам узла) |
| `query` | `SharedNodesQuery` | `undefined` | начальные `{ page, perPage }` |

**Эмиты**: `open-node(node)` (кросс-хранилищный — хост его маршрутизирует), `node-selected(node)`,
`action(action, node)`.
**Слоты**: `toolbar` (с `refresh`, `loading`), `title`, `refresh-label`, `error`, `empty`,
`name` / `context` (на строку), `load-more`.
**Экспоузит**: `refresh`, `loadMore`.

### `VaulterFolderPicker`

Модальный выбор назначения для **перемещения**: он обходит дерево **папок** хранилища (только папки) с
breadcrumb и действием «Переместить сюда» в текущей локации. Headless под стать остальному Drive —
минимальные scoped-стили, class-хуки + слоты, навигация управляется эмитами. Недопустимые назначения
никогда не предлагаются: перемещаемый узел скрыт из листинга (так что нельзя спуститься в его собственное
поддерево), а «Переместить сюда» отключено в *текущем* родителе узла (перемещение-no-op) или на самом узле.
Корень хранилища (родитель `null`) — выбираемое назначение.

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | обязательный |
| `node` | `Node` | — | обязательный — перемещаемый узел (его self/поддерево + текущий родитель исключены) |
| `open` | `boolean` | `false` | показан ли выбор (переоткрытие сбрасывает к корню) |
| `rootLabel` | `string` | `'Drive'` | подпись корневой крошки / корневого назначения |

**Эмиты**: `picked(targetId: NodeId \| null)` (`null` = корень хранилища), `close()`.
**Слоты**: `header` (с `node`), `folder` (на папку), `loading`, `empty`, `error`, `footer`
(с `move-here`, `can-move-here`, `current-parent-id`, `cancel`).
**Экспоузит**: `goTo`, `moveHere`, `currentParentId`, `folders`, `breadcrumb`, `canMoveHere`, `loading`.

### `VaulterNameDialog`

Крошечный доступный диалог **ввода имени** — одно текстовое поле для «новой папки», «переименования» или
любого потока ввода имени. Он повторяет модальную форму `VaulterFolderPicker` (бэкдроп + панель,
`role="dialog"`, закрытие по Esc / бэкдропу, фокус на открытии). На открытии поле засеивается из
`initialValue`, фокусируется и **полностью выделяется** (так что переименование предвыбирает текущее имя для
мгновенного перепечатывания). `submit` несёт **обрезанное** значение и срабатывает только когда оно
непустое; кнопка подтверждения отключена, пока обрезка пуста.

**Пропсы**

| Проп | Тип | По умолчанию | Примечания |
|------|------|---------|-------|
| `open` | `boolean` | `false` | показан ли диалог |
| `title` | `string` | `'Name'` | текст заголовка (например, `'New folder'` / `'Rename'`) |
| `label` | `string` | `'Name'` | доступный лейбл инпута |
| `initialValue` | `string` | `''` | предзаполнение — пусто для новой папки, имя узла для переименования |
| `confirmLabel` | `string` | `'Save'` | текст кнопки подтверждения |
| `placeholder` | `string` | `''` | плейсхолдер инпута |

**Эмиты**: `submit(name: string)` (обрезанное, только непустое), `close()`.
**Слоты**: `header` (с `title`), `footer` (с `submit`, `cancel`, `value`, `can-submit`).
**Экспоузит**: `value`, `submit`.

### Хелперы отображения

Два чистых хелпера подпирают рендеринг списка и экспортируются для переиспользования:

```ts
import { humanByteSize, nodeIconKind } from '@vaulter/vue'

humanByteSize(1_500_000)   // '1.4 MB' — null/undefined → '' (blank cell)
nodeIconKind(node)         // 'folder' | 'document' | 'image' | 'pdf' | 'archive' | 'audio' | 'video' | 'file'
```

`nodeIconKind` сворачивает несколько mime-семейств (image/pdf/archive/audio/video) в одну визуальную
корзину; перестилизуйте через класс `vaulter-node-icon--<bucket>` или построчный слот `icon`.

::: details Панель комментариев
Пакет также экспортирует `VaulterCommentsPanel` — панель со слотами для ветки комментариев
узла-документа (prop `node-id`). Её API см. в [Комментариях](/ru/guide/comments).
:::

## Разобранный пример: файловый менеджер

Полный экран диска — список, навигация, загрузка, открытие документа на редактирование и управление
грантами — собранный из `VaulterDrive`, `VaulterDocumentNode` и `VaulterNodeGrantsPanel`:

```vue
<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import {
  VaulterDrive,
  VaulterDocumentNode,
  VaulterNodeGrantsPanel,
  type Node,
} from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue' // the host-supplied TipTap editor

const props = defineProps<{ storageId: string }>()

// The node currently opened for editing (a file/document), null while browsing.
const openNode = shallowRef<Node | null>(null)
// The node whose grants we're managing in a side panel.
const grantsNode = shallowRef<Node | null>(null)

// Map the handler's ui.component to a concrete editor. The built-in TipTap keys
// (VaulterEditor / tiptap / richtext / rich-text) resolve to the host editor below.
const componentMap = { VaulterEditor: AppTipTapEditor }

function onOpenNode(node: Node) {
  if (node.kind === 'document') {
    openNode.value = node
  } else {
    // a plain file — download it
    window.open(`/api/vaulter/nodes/${node.id}/download`, '_blank')
  }
}

function onNodeSelected(node: Node) {
  // surface grants management for the selected node when the actor may share it
  grantsNode.value = node.abilities.share ? node : null
}
</script>

<template>
  <div class="drive-screen">
    <VaulterDrive
      :storage-id="props.storageId"
      :actions="['rename', 'move', 'copy', 'trash', 'download', 'share']"
      @open-node="onOpenNode"
      @node-selected="onNodeSelected"
    />

    <aside v-if="grantsNode" class="drive-screen__grants">
      <h3>Sharing — {{ grantsNode.name }}</h3>
      <VaulterNodeGrantsPanel :node-id="grantsNode.id">
        <!-- The host owns subject selection; wire your own picker here. -->
        <template #subject="{ setSubject }">
          <button type="button" @click="setSubject({ type: 'user', id: 42, label: 'Alice' })">
            Grant to Alice
          </button>
        </template>
      </VaulterNodeGrantsPanel>
    </aside>

    <dialog v-if="openNode" open class="drive-screen__editor">
      <header>
        <strong>{{ openNode.name }}</strong>
        <button type="button" @click="openNode = null">Close</button>
      </header>
      <VaulterDocumentNode
        :node-id="openNode.id"
        :component-map="componentMap"
        @saved="() => { /* toast 'Saved' */ }"
      />
    </dialog>
  </div>
</template>
```

Ключевые моменты:

- `VaulterDrive` обрабатывает навигацию, загрузку и подтверждения разрушающих действий внутренне; вы лишь
  реагируете на `open-node` и `node-selected`.
- Узел `document` открывается в `VaulterDocumentNode`, который резолвит обработчик, монтирует ваш
  TipTap-редактор (через `componentMap`) и автосохраняет с дебаунсом — или работает совместно, если
  обработчик это объявляет.
- `VaulterNodeGrantsPanel` управляет грантами ACL; вы поставляете только выбор субъекта.

## См. также

- [Админ-плагин Filament](/ru/guide/filament) — административный аналог.
- [Файлы и папки](/ru/guide/drive) — REST-маршруты Drive за этими composable-функциями.
- [Авторизация](/ru/concepts/authorization) — права, на которых гейтятся компоненты.
- [Совместное редактирование в реальном времени](/ru/guide/collaboration) — Yjs-транспорт, который открывает `useNodeCollaboration`.
