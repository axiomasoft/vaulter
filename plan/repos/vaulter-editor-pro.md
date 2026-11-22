# Репозиторий `vaulter-editor-pro` (Review, Comments, Compare и DOCX)

- **Git-репозиторий:** `axiomasoft/vaulter-editor-pro` (новый закрытый репозиторий; пишется с нуля по результатам `plan/tiptap-pro/` как собственная замена Tiptap Pro без зависимости от Tiptap Cloud/Pro)
- **Канал поставки:** Private / Proprietary (`FlexCrm`, `Edufy`)
- **План разработки:** [dev-plans/vaulter-editor-pro.md](../dev-plans/vaulter-editor-pro.md)

## Что располагается в репозитории

| Путь | Пакет | Ответственность |
| --- | --- | --- |
| `packages/review/` | `axiomasoft/vaulter-editor-pro-review` | PHP-домен рецензирования и истории: `v_document_revision_pins` (удержание ревизий при compaction), `v_document_comment_anchors` (generation-scoped диапазонные якоря поверх `v_node_comments`, CAS resolve/reopen, reanchor, restore-detach), `v_document_suggestions` (insert/delete/replace, группы, structural review, связь с `proposal_id`, атомарный accept/reject через `AtomicDocumentMutation` / `CommandJournal`) |
| `packages/exchange/` | `axiomasoft/vaulter-editor-pro-exchange` | PHP-домен серверного обмена документами: `v_document_exchange_jobs`, удержание Media blob, маршруты `POST /documents/{id}/exchange-jobs`, preview отчёта потерь, `GET …/file` по свежему ACL, `POST …/apply` с CAS и отдельным `command_id` |
| `packages/docx/` | `@vaulter/editor-pro-docx` | TypeScript (Node + Browser): единый версионированный mapping `Tiptap JSON/review ↔ intermediate model ↔ OOXML (DOCX)` на открытых ZIP/XML/OOXML библиотеках, корпус D1–D4, отчёт потерь `{profile, source_marker, diagnostics}`, офлайн-экспорт и серверный worker |
| `packages/vue/` | `@vaulter/editor-pro-vue` | Vue 3 + Tiptap OSS расширения: read-only History Preview & Structural Diff Compare (`EditorView` без live updates), Range Comments sidebar (active/resolved/detached), Tracked Changes overlay (`original`/`proposed`/`review`, IME/undo), UI локального и серверного DOCX |
| `tests/` | — | Синтетический корпус D1–D4, фикстуры `COMMENTS`, `REVIEW-HISTORY`, `REVIEW`, `DOCX`, `FILES`, изолированный consumer + Playwright E2E |

## Что запрещено в `vaulter-editor-pro`

- Любые runtime-зависимости от проприетарных пакетов `registry.tiptap.dev` (`@tiptap-pro/*`, Tiptap Cloud).
- Копирование минифицированного кода Tiptap Pro (только собственная чистая реализация по открытым спецификациям OOXML/ProseMirror/Yjs).
- Применение visual diff/compare к живому пишущему `EditorView` (всегда отдельная read-only модель).
- Попадание неподтверждённых suggestions в обычный `original` read Office SDK.

## Зависимости

- **Входящие:** `vaulter` (`documents` с `1.0`, `3.1`, `AtomicDocumentMutation`, `media`, `drive`, `@vaulter/vue`); опционально `vaulter-collaboration` (для `yjs_relative` anchors и strict journal accept/apply в `8.3` / `10.2`).
- **Исходящие артефакты:** `axiomasoft/vaulter-editor-pro-review`, `axiomasoft/vaulter-editor-pro-exchange`, `@vaulter/editor-pro-docx`, `@vaulter/editor-pro-vue`.
