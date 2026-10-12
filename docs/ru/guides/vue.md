---
title: Vue-пакет (@vaulter/vue)
description: UI Vaulter Drive для Vue 3 на REST v1 — генерируемые типы и типизированный клиент (If-Match, problem+json, проверка версии API), реестр viewer'ов на приложение с запасным вариантом для неподдерживаемых элементов, composable-функции и доступные компоненты, с разобранным примером файлового менеджера.
outline: deep
---

# Vue-пакет (`@vaulter/vue`)

`@vaulter/vue` — пакет на Vue 3 + TypeScript, который работает **только с REST API v1** и состоит из четырёх слоёв:

1. **типы, сгенерированные из контракта OpenAPI** (`openapi/vaulter-v1.yaml`): клиент не может разойтись с сервером;
2. **типизированный API-клиент** (`createVaulterClient`) с отдельным пространством имён на каждый ресурс;
3. **реестр viewer'ов**, который решает, каким компонентом показать узел, и безопасный запасной вариант;
4. **composable-функции** и **компоненты**, превращающие клиент в headless-UI Drive (`VaulterDrive`, …).

```bash
npm install @vaulter/vue
```

::: tip Peer-зависимости
Peer-зависимости: `vue@^3.4`, `yjs@^13.6` и `@tiptap/pm@^3`. Транспорт совместного редактирования тянет
`@hocuspocus/provider`; сам редактор (TipTap, Univer, …) — ваш, см. [viewer'ы](#viewer-ы).
:::

## Настройка

### Как плагин

Установите плагин один раз. Он собирает клиент из ваших опций, создаёт реестр viewer'ов **этого приложения**,
предоставляет i18n и регистрирует все компоненты глобально:

```ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter/v1',               // по умолчанию; сегмент версии входит в базу
    csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content,
    viewers: {
      'acme/diagram': () => import('./viewers/DiagramViewer.vue'), // ваш viewer
    },
    i18n: { locale: 'ru' },
  })
  .mount('#app')
```

| Опция | Тип | Примечания |
|-------|-----|------------|
| `client` | `VaulterClient` | готовый клиент вместо собранного из опций (тесты / своя обвязка) |
| `viewers` | `Record<string, ViewerEntry>` | добавляются к встроенным; переданный ключ **заменяет** встроенный |
| `i18n` | `{ locale?, messages? }` | `en` (по умолчанию) и `ru` поставляются с пакетом; `messages` переопределяет отдельные строки по локалям |
| `baseUrl`, `headers`, `csrfToken`, `fetch` | см. ниже | передаются в `createVaulterClient` |

### Опции клиента

| Опция | Тип | По умолчанию | Примечания |
|-------|-----|--------------|------------|
| `baseUrl` | `string` | `/api/vaulter/v1` | совпадает с `routes.prefix` + `/v1`. Хвостовые слэши обрезаются |
| `headers` | `Record<string, string>` или `() => Record<string, string>` | — | дополнительные заголовки или функция-геттер (например, для ротируемого bearer-токена) |
| `csrfToken` | `string` | — | отправляется как `X-CSRF-TOKEN` |
| `fetch` | `typeof fetch` | глобальный `fetch` | своя реализация fetch |

Для тестов или SSR-обвязки на запрос соберите клиент сами и предоставьте его:

```ts
import { createVaulterClient, provideVaulterClient } from '@vaulter/vue'

provideVaulterClient(app, createVaulterClient({ baseUrl: '/api/vaulter/v1' }))
```

Внутри компонента `useVaulterClient()` возвращает клиент и понятной ошибкой сообщает, что он не предоставлен.

## Типы

`src/api/schema.d.ts` **генерируется** из `openapi/vaulter-v1.yaml` утилитой `openapi-typescript` и хранится в git;
руками его не правят. Пакет экспортирует готовые алиасы — `Node`, `Drive`, `FileRevision`, `DocumentRevision`,
`Grant`, `Share`, `MintedShare`, `Comment`, `Activity`, `Change`, `CollaborationSession`, `Problem`, … — а также
сырые `components`, `paths` и `operations` для всего остального. Идентификаторы — всегда строки.

```bash
npm run api:generate   # перегенерировать после изменения контракта
npm run api:check      # CI: падает, если файл устарел или не отслеживается git
```

## API-клиент

`createVaulterClient()` возвращает `VaulterClient` с пространством имён на каждый ресурс, повторяющим маршруты v1:

| Пространство | Что покрывает |
|--------------|---------------|
| `drives` | `get`, `archive`, `unarchive`, `delete` |
| `nodes` | `children`, `search`, `starred`, `shared`, `get`, `getWithIncludes`, `update` (переименование), `setProperties`, `move`, `copy`, `trash`, `restore`, `purge`, `star`, `unstar`, `abilities` |
| `trash` | `list`, `empty` |
| `folders` | `create` |
| `files` | `upload`, `download`, `downloadUrl`, `revisions`, `addRevision`, `downloadRevision`, `restoreRevision`, `setRevisionRetention`, `deleteRevision` |
| `uploads` | `start` (возобновляемая загрузка tus или S3 multipart), `show` |
| `documents` | `create`, `content`, `update`, `export`, `revisions`, `revision`, `snapshot`, `setRevisionRetention`, `restoreRevision`, `collaborationSession` |
| `grants`, `access` | `list`, `create`, `revoke`; `restrictInheritance`, `restoreInheritance` |
| `shares`, `publicShares` | `list`, `create`, `update`, `rotate`, `revoke`; `get`, `nodes`, `downloadUrl` |
| `comments`, `activity`, `changes` | обсуждения; ленты активности; `start` и `list` ленты изменений |

```ts
const client = useVaulterClient()

const page = await client.nodes.children(driveId, { parent: null, perPage: 50 }) // { data, meta }
const folder = await client.folders.create(driveId, { name: 'Invoices' })
const renamed = await client.nodes.update(folder.id, { name: 'Bills' }, { etag: folder.etag })
```

### Страницы по курсору

Каждый список **постраничный по курсору** и возвращает `{ data, meta: { next_cursor, per_page } }` — без номера
страницы и без общего числа. Передайте `meta.next_cursor` обратно как `{ cursor }`. Лента изменений использует
`{ cursor, limit }` и отвечает `meta.has_more`.

### Предусловия: `If-Match` и ETag

`etag` узла — **непрозрачный валидатор** представления, которое у вас на руках. Не разбирайте его и не собирайте
сами. Каждый метод, меняющий узел или документ, принимает его **обязательной** опцией `{ etag }` и отправляет как
`If-Match`:

```ts
await client.nodes.trash(node.id, { etag: node.etag })
await client.nodes.move(node.id, targetId, { etag: node.etag, onConflict: 'rename' })
await client.documents.update(node.id, content, { etag: loaded.etag! })
```

Вызов без `etag` не пройдёт проверку типов. Валидатор содержимого документа приходит в заголовке ответа `ETag`;
клиент отдаёт его как `content.etag` (`null`, если заголовок не виден браузеру: для кросс-доменного API добавьте
`ETag` в `Access-Control-Expose-Headers`).

| Ответ | `error.code` | Что значит |
|-------|--------------|------------|
| `412` | `node_sequence_mismatch`, `document_state_mismatch` | ресурс изменили после того, как вы его прочитали — прочитайте заново |
| `428` | `precondition_required` | вызов ушёл без `If-Match`: ошибка интеграции, а не состояние, из которого восстанавливаются |

### Ошибки

Ответ не из 2xx бросает `VaulterApiError`, разобранный из `application/problem+json` (RFC 9457):

```ts
try {
  await client.nodes.update(node.id, { name }, { etag: node.etag })
} catch (error) {
  if (error instanceof VaulterApiError) {
    error.status     // 422
    error.code       // 'invalid_node_name'  (строка; новые коды в v1 добавляются без поломки)
    error.title      // локализованное краткое описание
    error.detail     // локализованное пояснение или null
    error.requestId  // укажите в отчёте об ошибке
    error.meta       // { reason: 'reserved_name' }
    error.errors     // ошибки полей при 422 validation_failed или null
  }
}
```

Ответ, который не является problem-документом (HTML 502 от прокси, пустой 504), превращается в
`code: 'http_<status>'`, а не в ошибку разбора.

### Версия API

Решает первый ответ с заголовком `X-Vaulter-Api-Version`: мажорная версия не `1` бросает `VaulterApiError` с
`code: 'api_version_mismatch'` для этого вызова **и всех последующих** — клиент одного мажора не работает «вполовину»
с другим.

### Загрузки

`client.files.upload()` — загрузка одним запросом. `client.uploads.start()` возвращает возобновляемую загрузку (tus
по умолчанию, `protocol: 's3'` для S3 multipart), которая всегда продолжает с серверного смещения:

```ts
const upload = client.uploads.start(driveId, file, { parentId, onProgress: (sent, total) => {} })
const node = await upload.start()   // позже: await upload.abort()
```

Замена содержимого существующего файла требует его тег: `{ replace: { nodeId, etag } }`.

## Viewer'ы

Сервер сообщает, **каким viewer'ом** показать узел, в `node.presentation.viewer.key` (и его `props`). Реестр сопоставляет
ключи компонентам. Он принадлежит **одному приложению** (`provide/inject`): два приложения или два SSR-запроса никогда
не видят viewer'ы друг друга.

```ts
app.use(VaulterPlugin, {
  viewers: {
    'acme/diagram': () => import('./DiagramViewer.vue'),  // обычная функция — ленивый загрузчик
    'vaulter/download': MyDownloadViewer,                 // заменяет встроенный
  },
})
```

Компонент viewer'а получает `node` и `readonly` (плюс `viewer.props` с сервера, которые не могут их переопределить).
Встроенные ключи: `vaulter/tiptap`, `vaulter/univer` (headless-оболочки редактирования `VaulterDocumentNode` /
`VaulterSpreadsheet` — движок редактора монтируете **вы** в их слоте или заменяете ключ), `vaulter/pdf-preview`,
`vaulter/image-preview` (показ идёт через авторизованное скачивание, поэтому *previewer* без права `download` не
получает байтов), `vaulter/download` и `vaulter/unsupported`.

`<VaulterViewer :node="node" />` монтирует нужный. Узел **без** presentation, с ключом, который приложение не
регистрировало, или документ типа, который приложение не умеет (`presentation.type_available === false`) показывает
`VaulterUnsupportedNode`: имя, тип документа или mime, причину и кнопку скачивания — только для **файла** и только при
`abilities.download`. Сам запасной вариант можно заменить через `vaulter/unsupported`.

## Composable-функции

Все composable-функции вызывают `useVaulterClient()`, поэтому работают под предоставленным клиентом. Они возвращают
реактивные ref'ы и асинхронные методы.

| Composable | Назначение |
|------------|------------|
| `useDrive(driveId)` | загрузить один диск |
| `useNodes(driveId, parent?)` | список и навигация по папке; `createFolder`, `rename`, `move`, `copy`, `trash`, `restore`, `purge`, `star`, `unstar`; `conflict` |
| `useSharedNodes(query?)` | плоский список «доступно мне» |
| `useUpload(driveId)` | прямая и возобновляемая загрузка с прогрессом |
| `useGrants(nodeId)`, `useShares(nodeId)` | гранты и ссылки доступа (сырой токен хранится один раз в `lastCreated`) |
| `useActivity({ driveId } \| { nodeId })` | лента активности |
| `useComments(nodeId)` | обсуждение |
| `useDocument(nodeId)` | загрузка, отложенное автосохранение, `conflict`, экспорт |
| `useRevisions(nodeId)` | история документа: `snapshot`, `setRetention`, `restore` |
| `useCollaboration(nodeId)` | сессия реального времени (ниже) |

**Мутации принимают узел, а не его id**, потому что сервер сравнивает его `etag`:

```ts
const { nodes, rename, conflict } = useNodes(driveId)
await rename(node, 'новое имя')       // отправляет If-Match: node.etag
```

При `412` composable **перечитывает узел** (список уже показывает победившую версию), записывает в `conflict`
`{ nodeId, error }` и пробрасывает ошибку дальше. `428` пробрасывается как есть. `useDocument` ведёт себя так же для
документа: устаревшее сохранение оставляет ваш локальный `content`, обновляет `head` (серверное содержимое и тег) и
выставляет `conflict`; вызовите `save()`, чтобы перезаписать своими правками, или `load()`, чтобы взять версию сервера.

`VaulterDrive` показывает New folder и Upload только при `abilities.create = true` у текущей
папки. В корне drive он загружает способности корневого узла; до ответа или при ошибке
проверки действия создания скрыты. Пользовательский слот `toolbar` получает `canCreate`
вместе с `newFolder`. Навигация и `refresh()` перечитывают разрешение, включая gate профиля.

### Совместное редактирование

`useCollaboration(nodeId, { supportedSchemaVersion, onEpochChanged })` запрашивает сессию
(`documents.collaborationSession`) и открывает провайдер Hocuspocus на `Y.Doc`, который вы привязываете расширением
Collaboration из TipTap. Содержимое всегда приходит от хаба — не заполняйте редактор сами.

- `readOnly` истинно для сессии `ro` **или** если схема сервера новее `supportedSchemaVersion` (`schemaOutdated`):
  монтируйте редактор с `editable: false` и попросите пользователя обновить приложение.
- Когда хаб закрывает комнату кодом `4409 epoch_changed` (документ восстановили), старый `Y.Doc` уничтожается, его
  заменяют новые сессия и **новый** `Y.Doc`, после чего вызывается `onEpochChanged` — перечитайте содержимое и его ETag.
- `4001` (токен истёк) и `4403` (право на запись потеряно) обновляют сессию; `4422` не повторяется.

`connect()`, который перехватил другой `connect()`/`disconnect()`, отклоняется с `CollaborationCancelledError` — это не сбой сессии.

`createCollaborationSession(client, nodeId, options)` делает то же один раз, без логики переоткрытия.

## Компоненты

Все компоненты headless: классы-хуки (`vaulter-*`), слоты и немного scoped-стилей. Действия строк гейтятся по
`abilities` узла — кнопка или клавиша не предлагается для права, которое сервер не выдал.

| Компонент | Примечания |
|-----------|------------|
| `VaulterDrive` | файловый менеджер: хлебные крошки, панель, список, диалоги. Props: `driveId`, `initialParentId`, `viewMode`, `actions`, `confirm`. События `open-node`, `node-selected`, `navigate` |
| `VaulterNodeList` | список/сетка; см. [доступность](#доступность). Действия: `rename` (нужно `edit`), `move`, `copy`, `trash`, `restore`, `purge` (нужно `delete`), `download`, `share` |
| `VaulterBreadcrumb` | `path` из `{ id, name }`, событие `navigate` |
| `VaulterFolderPicker` | выбор папки назначения для перемещения; скрывает перемещаемую папку и её поддерево |
| `VaulterNameDialog` | ввод имени с [проверкой portable-v1](#имена) |
| `VaulterUploader` | зона перетаскивания, прогресс по файлам, ошибки объявляются в области `aria-live` |
| `VaulterGrantsPanel`, `VaulterSharePanel` | управление ACL и ссылками доступа |
| `VaulterCommentsPanel` | обсуждение |
| `VaulterSharedWithMe` | список «доступно мне» по всем дискам |
| `VaulterViewer`, `VaulterUnsupportedNode` | см. [viewer'ы](#viewer-ы) |
| `VaulterDocumentNode`, `VaulterSpreadsheet` | оболочки редактирования (загрузка, автосохранение, совместная работа, конфликт) для **вашего** редактора |

Хелперы отображения: `humanByteSize(bytes)` и `nodeIconKind(node)`.

### Имена

`VaulterNameDialog` проверяет имя функцией `portableNameReason(name)` — теми же правилами в том же порядке, что и
серверная политика **`portable-v1`** по умолчанию (без `< > : " | ? *`, без точки в конце, без имён устройств Windows и
`desktop.ini`, не более 255 байт UTF-8 и единиц UTF-16, NFC). Причины совпадают с серверным `meta.reason`: `empty`,
`path_alias`, `separator`, `control_char`, `reserved_char`, `trailing_dot_or_space`, `leading_or_trailing_space`,
`reserved_name`, `too_long`. Если сервер всё же отклонил имя (диск со строже политикой), передайте ошибку как
`:server-error` — диалог покажет её причину.

### Доступность

`VaulterNodeList` — **listbox** WAI-ARIA из options с одной точкой табуляции (roving `tabindex`):

| Клавиша | Действие |
|---------|----------|
| `↑` `↓`, `Home`, `End` | перемещают фокус |
| `Enter` | открыть (папка — перейти) |
| `Space` | выбрать |
| `F2` | переименовать (если можно редактировать) |
| `Delete` | в корзину с `etag` узла (если можно в корзину) |

После того как узел покинул список (корзина, перемещение, удаление), фокус переходит на соседний элемент, а не уходит
на страницу. Ошибки — отклонённая мутация (`role="alert"`) и сбой загрузки (`aria-live="assertive"`) — объявляются.

## Разобранный пример: файловый менеджер

```vue
<script setup lang="ts">
import { shallowRef } from 'vue'
import { VaulterDrive, VaulterViewer, VaulterGrantsPanel, type Node } from '@vaulter/vue'

const props = defineProps<{ driveId: string }>()

const openNode = shallowRef<Node | null>(null)    // открыт во viewer'е
const grantsNode = shallowRef<Node | null>(null)  // управляется в боковой панели

function onNodeSelected(node: Node) {
  // Кто может делиться, решает сервер; интерфейс только следует.
  grantsNode.value = node.abilities.share ? node : null
}
</script>

<template>
  <VaulterDrive
    :drive-id="props.driveId"
    :actions="['rename', 'move', 'copy', 'trash', 'download', 'share']"
    @open-node="openNode = $event"
    @node-selected="onNodeSelected"
  />

  <aside v-if="grantsNode">
    <VaulterGrantsPanel :node-id="grantsNode.id">
      <template #subject="{ setSubject }">
        <button type="button" @click="setSubject({ type: 'user', id: '42' })">Выдать Алисе</button>
      </template>
    </VaulterGrantsPanel>
  </aside>

  <dialog v-if="openNode" open>
    <button type="button" @click="openNode = null">Закрыть</button>
    <!-- Тот viewer, который выбрал сервер; неизвестные откатываются на безопасный просмотр «скачать». -->
    <VaulterViewer :node="openNode" />
  </dialog>
</template>
```

Зарегистрируйте редактор документов один раз в опциях плагина — viewer получает узел и монтирует ваш TipTap в слоте
`VaulterDocumentNode`:

```ts
app.use(VaulterPlugin, { viewers: { 'vaulter/tiptap': () => import('./AppDocumentViewer.vue') } })
```

## См. также

- [Справочник REST API](/ru/reference/rest-api) — контракт, из которого генерируется этот пакет.
- [Файлы и папки](/ru/concepts/nodes-and-revisions) — маршруты Drive за этими composable-функциями.
- [Авторизация](/ru/concepts/access-model) — права, на которых гейтятся компоненты.
- [Совместное редактирование в реальном времени](/ru/guides/collaboration) — Yjs-транспорт, который открывает `useCollaboration`.
- [Руководство по обновлению](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) — что изменилось по сравнению с пакетом до v1.
