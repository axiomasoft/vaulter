# Первый проход установки Pro · 2026-11-21

Индивидуальная установка выполнена через `scripts/install-pro.mjs`, npm-токен прочитан из исключённого корневого `.env`. npm argv и отчёты токен не содержат. Версии зафиксированы в `package.json` и `npm-shrinkwrap.json`.

После первого прохода отдельно установлены Basic AI 3.12.9, открытый Server AI Toolkit 0.5.0, PageKit, Pages TableKit, PasteHandler и пять дополнительных форматов экспорта. Их версии и отказы реестра приведены в [каталоге модулей](../module-catalog.md) и [инвентаризации](../results/additional-pro-source-inventory.json).

| Пакет | Результат registry / версия | Использование в стенде |
| --- | --- | --- |
| `@tiptap-pro/provider` | Установлен, 3.12.9 | Cloud transport, thread/version API |
| `@tiptap-pro/extension-comments` | Установлен, 3.12.9 | `CommentsKit`, queries с anchor ranges, команды thread/reply/resolve |
| `@tiptap-pro/extension-snapshot` | Установлен, 3.12.9 | `Snapshot`, save/list/restore, preview callback |
| `@tiptap-pro/extension-snapshot-compare` | Установлен, 3.12.9 | Опциональный флаг схемы, `compareVersions` |
| `@tiptap-pro/extension-tracked-changes` | Установлен, 0.14.3 | `TrackedChanges`, `findSuggestions`, tracking/accept/reject |
| `@tiptap-pro/extension-convert-kit` | Установлен, 0.16.1 | Доступен для последующих отдельных Conversion-сценариев; в базовом редакторе не зарегистрирован |
| `@tiptap-pro/extension-export-docx` | Установлен, 0.34.4 | [Изолированный опыт DOCX](../conversion-design.md) |
| `@tiptap-pro/compare` | Registry HTTP 403 | Не установлен; это отдельный пакет, не Snapshot Compare |
| `@tiptap-pro/extension-import-docx` | Registry HTTP 403 | Не установлен |
| `@tiptap-pro/client-ai-toolkit` | Registry HTTP 403 | Не установлен |

Первый проход Comments/Snapshot/Export DOCX завершился TLS transport errors (`ERR_SSL_DECRYPTION_FAILED_OR_BAD_RECORD_MAC`, `ERR_SSL_PACKET_LENGTH_TOO_LONG`). Повторная установка удалась. Это отличалось от HTTP 403, поэтому installer сохраняет safe npm code и повторяет только ошибки транспорта. При `--only=...` обновляется запись выбранных пакетов с сохранением остальных результатов.

## Совместимость

Проверено `npm ls @tiptap/core @tiptap/pm @hocuspocus/provider yjs --all`. В дереве одна версия каждой из библиотек: Tiptap core/pm 3.35.0, Hocuspocus provider 4.9.0, Yjs 13.6.33. Pro provider 3.12.9 декларирует peer Hocuspocus `^4.0.0`; Tracked Changes 0.14.3 — core/pm `^3.30.1`; Snapshot Compare — core/pm `^3.15.0`. Установленные версии удовлетворяют диапазонам. `--force` и `--legacy-peer-deps` не использовались.

## Используемые публичные контракты

Сверены `dist/index.d.ts`, включённые в установленные пакеты `docs/api.md`, и имена runtime exports. Vendor-файлы не копируются в репозиторий или отчёты.

- Provider: `getThreads`, `getVersions`, `listVersions`, `createVersion`, `previewVersion`, `revertToVersion`, `watchThreads`, `watchVersions`; события `saved` и `stateless`.
- Comments: регистрируется только `CommentsKit`, без повторного добавления внутренних node/mark extensions. Команды `setThread`, `createComment`, `resolveThread`, `unresolveThread`, `removeThread`; query `getThreads({ editor, provider, getThreadsOptions })` возвращает отдельные ranges каждого anchor.
- Snapshot: `saveVersion(name, force, meta)` — boolean принятия команды, не server completion. Preview запрашивает provider; `watchPreviewContent` возвращает unsubscribe и передаёт JSON без изменения live document.
- Tracked Changes: регистрируется только `TrackedChanges`, который сам добавляет mark/node attributes. `findSuggestions(editor)` возвращает текущие proposals; `acceptSuggestion({ id })` и `rejectSuggestion({ id })` могут выполняться другим клиентом. Включение tracking локально редактору не изменяет авторизацию provider.

Доступ к npm-пакету сам по себе не подтверждает доступность backend-функции или entitlement. Для этого нужны отдельные Cloud-сценарии и сохранённые результаты выполнения.
