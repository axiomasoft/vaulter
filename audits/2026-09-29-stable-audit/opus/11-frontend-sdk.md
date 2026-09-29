# 11 — `@vaulter/vue` и хаб

Решение: [D32](02-decisions.md#d32). Пакет остаётся одним (ADR-0018 D8), версия — независимая npm-линия,
совместимость объявляется версией REST API.

## 1. Структура

```text
packages/vue/src/
├── index.ts                     # публичные экспорты (snapshot-тест списка экспортов)
├── plugin.ts                    # VaulterPlugin: client + viewers + i18n
├── api/
│   ├── schema.d.ts              # СГЕНЕРИРОВАН из openapi/vaulter-v1.yaml (не править руками)
│   ├── client.ts                # createVaulterClient(): тонкая обёртка над fetch, типы из schema.d.ts
│   ├── errors.ts                # VaulterApiError { status, code, detail, requestId, meta }
│   └── uploads/{tus.ts,s3.ts}
├── viewers/
│   ├── registry.ts              # createViewerRegistry(), useViewerRegistry()
│   ├── builtin.ts               # ключи vaulter/tiptap, vaulter/univer, vaulter/pdf-preview, vaulter/image-preview, vaulter/download, vaulter/unsupported
│   └── VaulterViewer.vue        # монтирует компонент по presentation.viewer.key
├── composables/                 # useDrive, useNodes, useUpload, useGrants, useShares, useActivity,
│                                # useDocument, useRevisions, useComments, useCollaboration
├── components/                  # VaulterDrive, VaulterNodeList, VaulterBreadcrumb, VaulterFolderPicker,
│                                # VaulterUploader, VaulterGrantsPanel, VaulterSharePanel, VaulterCommentsPanel,
│                                # VaulterSharedWithMe, VaulterNameDialog, VaulterUnsupportedNode
├── editors/                     # TipTapEditor.vue, SpreadsheetEditor.vue (ленивые import())
└── collaboration/               # createCollaborationSession (Hocuspocus provider, epoch/read-only)
```

## 2. Реестр viewer'ов (вместо модульного `Map`)

```ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'

createApp(App).use(VaulterPlugin, {
  baseUrl: '/api/vaulter/v1',
  viewers: {
    'acme/diagram': () => import('./viewers/DiagramViewer.vue'),   // расширение хоста
  },
})
```

- Реестр создаётся на экземпляр приложения и отдаётся через `provide/inject` (`useViewerRegistry()`); SSR-safe.
- Встроенные ключи регистрируются плагином; хост может заменить любой.
- Разрешение: `presentation.viewer.key` → компонент; нет ключа или `type_available = false` →
  `VaulterUnsupportedNode` (имя, тип, кнопка «Скачать», если `abilities.download`).
- `registerEngineComponent`/`resolveEngineComponent`/… удаляются (0.x, D01).

## 3. Клиент и типы

- Все типы запросов/ответов — из `api/schema.d.ts` (`openapi-typescript`). Ручной `types.ts` удаляется;
  доменные алиасы (`Node`, `Drive`, `FileRevision`, `DocumentRevision`, …) — `type Node = components['schemas']['Node']`.
- Клиент сверяет `X-Vaulter-Api-Version` при первом ответе: несовпадение мажора → `VaulterApiError('api_version_mismatch')`.
- Ошибки — разбор `application/problem+json`; `code` доступен как строковый union из схемы + `string`.
- ID — всегда `string`.
- `ETag`/`If-Match`: мутации узлов принимают `expectedSequence?`; клиент ставит `If-Match`; на 412 composables
  перечитывают узел и сообщают о конфликте.

Методы клиента (по ресурсам, повторяют REST v1):

```ts
client.drives.get(driveId)
client.nodes.children(driveId, { parent, cursor, perPage })   client.nodes.search(driveId, q, …)
client.nodes.get(nodeId)   .update(nodeId, patch, { expectedSequence })   .move(nodeId, destinationId, …)
client.nodes.copy(nodeId, destinationId, { name })   .trash(nodeId)   .restore(nodeId, opts)   .purge(nodeId)
client.nodes.abilities(nodeId, { explain })
client.trash.list(driveId, …)   client.trash.empty(driveId)
client.folders.create(driveId, { name, parentId })
client.files.upload(driveId, file, { parentId, onConflict })   client.files.download(nodeId)
client.files.revisions(nodeId)   .addRevision(nodeId, file)   .downloadRevision(nodeId, revId)   .restoreRevision(nodeId, revId)
client.uploads.start(driveId, file, { protocol: 'tus' | 's3', parentId })   // resumable, возвращает ResumableUpload
client.documents.create(driveId, { type, name, parentId, content })
client.documents.content(nodeId)   .update(nodeId, content, { expectedSequence })   .export(nodeId, format)
client.documents.revisions(nodeId)   .snapshot(nodeId, { label, keepForever })   .restore(nodeId, revId)   .setRetention(…)
client.documents.collaborationSession(nodeId)
client.grants.list(nodeId)   .create(nodeId, input)   .revoke(nodeId, grantId)
client.shares.list(nodeId)   .create(nodeId, input)   .revoke(nodeId, shareId)   client.publicShares.get(token) …
client.comments.list(nodeId)   .add(nodeId, input)   .resolve(nodeId, id)   .remove(nodeId, id)
client.activity.forDrive(driveId)   .forNode(nodeId)
```

## 4. Коллаборация на клиенте

- `createCollaborationSession(nodeId)` запрашивает сессию; режим `ro` → редактор `editable: false`.
- При закрытии хабом с причиной `epoch_changed` клиент перечитывает `content` и пересоздаёт Y.Doc
  (не мёрджит старое состояние).
- `schema_version` из сессии сравнивается с поддерживаемой редактором; старший сервер → read-only +
  предупреждение «обновите приложение».

## 5. Доступность (минимум для 1.0)

Дерево/список — паттерн WAI-ARIA listbox/grid (что реально соответствует UI; tree только если есть
раскрытие веток): управление с клавиатуры (стрелки, Enter — открыть, F2 — переименовать, Delete — в корзину),
фокус после мутаций остаётся на соседнем элементе, ошибки загрузки объявляются `aria-live`. Тест — Vitest +
@testing-library/vue на клавиатурные сценарии.

## 6. Хаб (`hocuspocus-server`)

- Эндпоинты Laravel: `v1/collaboration/hub/authenticate|store`; конфиг хаба — `VAULTER_API_URL` с `/v1`.
- Серверная проверка read-only: для соединения `mode=ro` входящие updates отбрасываются (`beforeHandleMessage`).
- Передача `epoch` в `store`; на 409 — `document.unload` + закрытие соединений комнаты с кодом `epoch_changed`.
- Redis-расширение для нескольких инстансов — T2 (ROADMAP).
