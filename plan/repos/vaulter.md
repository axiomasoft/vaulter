# Репозиторий `vaulter` (Базовая публичная платформа)

- **Git-репозиторий:** `axiomasoft/vaulter` (существует, рабочий путь `/home/vostrikov/projects/packages/vaulter`)
- **Канал поставки:** Public (открываемый код для FlexCms и базовый фундамент для всех закрытых репозиториев)
- **План разработки:** [dev-plans/vaulter.md](../dev-plans/vaulter.md)

## Что располагается в репозитории

| Путь | Пакет (Composer / npm) | Ответственность |
| --- | --- | --- |
| `packages/core/` | `axiomasoft/vaulter-core` | Контекст операции, транзакции (`OperationRunner`), outbox, идемпотентность |
| `packages/media/` | `axiomasoft/vaulter-media` | CAS-хранилище блобов, `v_blobs`, `v_blob_references`, сборка мусора (GC) |
| `packages/drive/` | `axiomasoft/vaulter-drive` | Диски, дерево узлов, ACL (`DriveContext`, grants, locks), аудит |
| `packages/documents/` | `axiomasoft/vaulter-documents` | Универсальный агрегат документа (`write_mode`, разделённые payloads/sync_states), ревизии, `blockId` (schema v2), порт `AtomicDocumentMutation`, `RequiresCommandWrite`, базовые типы `vaulter/rich-text` и `vaulter/markdown`, базовые комментарии узла |
| `packages/vaulter/` | `axiomasoft/vaulter` | Базовый метапакет (`core` + `media` + `drive` + `documents`) |
| `packages/vue/` | `@vaulter/vue` | Публичный HTTP-клиент, `VaulterPlugin`, `VaulterDrive`, `VaulterViewer` (с fallback), `VaulterDocumentNode`, опциональное Tiptap-расширение `block-id.ts` |
| `packages/webhooks/` | `axiomasoft/vaulter-webhooks` | Дополнительный публичный пакет вебхуков |
| `packages/filament/` | `axiomasoft/vaulter-filament` | Дополнительный публичный пакет Filament |
| `packages/azguard/` | `axiomasoft/vaulter-azguard` | Дополнительный публичный пакет интеграции с AzGuard |
| `e2e/consumers/documents-basic/` | — | Изолированный эталонный потребитель базовой поставки (Drive → Tiptap → Save/412/403) |

## Что выносится и запрещено в `vaulter`

- **Выносится в `vaulter-sheets` (1.1–1.2):** `SpreadsheetDocumentType`, `VaulterSpreadsheet.vue`, регистрация `vaulter/spreadsheet` и `vaulter/univer`.
- **Выносится в `vaulter-collaboration` (2.0):** `packages/collaboration/`, `packages/vue-collaboration/`, `packages/collaboration/hub/`, коллаборационный E2E, а также удаляются legacy-каталоги `hocuspocus-server/` и `packages/azgard/`.
- **Запрещено:** любой код/зависимости Yjs/Hocuspocus, Univer/Sheets, AI SDK/LLM, Range Comments/Tracked Changes/DOCX, приватные записи в `release/packages.json`.

## Зависимости

- **Входящие:** нет (корневой репозиторий экосистемы; опирается только на публичные Laravel 13, PHP 8.4, Vue 3, Packman).
- **Исходящие артефакты для других репозиториев:** публичные Composer ZIP (`vaulter`, `core`, `media`, `drive`, `documents`) и npm tgz (`@vaulter/vue`) с зафиксированными SHA-256.
