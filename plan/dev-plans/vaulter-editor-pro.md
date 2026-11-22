# План разработки репозитория `vaulter-editor-pro` (Review, Comments, Compare и DOCX)

**Паспорт:** [repos/vaulter-editor-pro.md](../repos/vaulter-editor-pro.md) · **Спецификация:** [spec/implementation/editor-pro.md](../spec/implementation/editor-pro.md) · **Схема БД:** [spec/database/editor-pro.md](../spec/database/editor-pro.md) · **DDL:** [editor-pro-review.sql](../spec/database/sql/editor-pro-review.sql) + [editor-pro-exchange.sql](../spec/database/sql/editor-pro-exchange.sql)

Новый закрытый репозиторий, реализуемый с нуля без зависимости от Tiptap Pro / Tiptap Cloud. Покрывает фазы `08` (история preview/compare, Range Comments, Tracked Changes, Structural Review) и `10` (DOCX corpus, локальный экспорт и серверный обмен файлами).

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `8.1` | W3 | `1.0`, `3.1` (`vaulter`) | **Gemini Neon** | GPT-6.2 Sol | `packages/review` (`v_document_revision_pins`, `v_document_comment_anchors`), `packages/vue` (read-only preview/compare + Range Comments sidebar) |
| 2 | `10.1` | W3 | `1.0`, `3.1` (`vaulter`) | **Gemini Neon** | Gemini Carbon | `packages/docx` (`@vaulter/editor-pro-docx`), корпус D1–D4, intermediate model ↔ OOXML, loss report, локальный офлайн-экспорт в `packages/vue` |
| 3 | `8.2` | W5 | `3.3`, `8.1` | **GPT-6.2 Sol** | **Astra** | `v_document_suggestions` (insert/delete/replace, группы, связь с `proposal_id`), атомарный accept/reject, Tiptap OSS review overlay (`original`/`proposed`/`review`, IME/undo) |
| 4 | `10.2` | W5 | `3.3`, `10.1` | **Gemini Neon** | **Astra** | `packages/exchange` (`v_document_exchange_jobs`, Media retention, маршруты preview/file/apply с CAS и `command_id`), UI импорта/экспорта в `packages/vue` |
| 5 | `8.3` | W6 | `3.5`, `8.2` | **Gemini Neon** | GPT-6.2 Sol | Структурный review (`mark_change`, `attr_change`, `block_split`, `block_join`, `list_change`, `table_change`), политика пересечений v1, работа в snapshot и strict journal |

## Детальные инструкции по шагам

### Шаг 1 (`8.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Инициализировать репозиторий `vaulter-editor-pro` (scaffold через Gemini Argon): корневой `composer.json` (`packages/review`, `packages/exchange`), корневой `package.json` workspaces (`packages/docx`, `packages/vue`), `release/packages.json`, `tests/`.
- В `packages/review` (`axiomasoft/vaulter-editor-pro-review`):
  - Создать миграции `v_document_revision_pins` и `v_document_comment_anchors` строго по `plan/spec/database/sql/editor-pro-review.sql`.
  - Реализовать привязку range anchors к существующим `v_node_comments` (`snapshot_utf16` для snapshot, `yjs_relative` для journal при установленном `vaulter-collaboration`), сохранив обратную совместимость с обычными node comments.
  - Реализовать CAS resolve/reopen, replies, reanchor, автоматический переход в `detached` при удалении текста и в `historical`/`detached` при restore.
- В `packages/vue` (`@vaulter/editor-pro-vue`):
  - Read-only History Preview & Structural Diff Compare в отдельном `EditorView` (гарантия: 0 live updates).
  - Range Comments sidebar (active / resolved / detached, кнопка reanchor, индикатор актуальности).
- **Проверка:** сценарии `COMMENTS` и `REVIEW-HISTORY` из `plan/tiptap-pro/acceptance-matrix.md` (сдвиг текста, частичное/полное удаление, гонка resolve/reopen, запрет spoof автора через Yjs, доступность закреплённой ревизии после compaction).

### Шаг 2 (`10.1`) — Gemini Neon → ревью Gemini Carbon
- В `packages/docx` (`@vaulter/editor-pro-docx`):
  - Зафиксировать точные версии OSS ZIP/XML/OOXML библиотек (без внешних сетевых вызовов и без Pro-зависимостей).
  - Реализовать версионированный mapping `Tiptap JSON/review ↔ intermediate model ↔ OOXML` и отчёт `{profile, source_marker, diagnostics:[{code,severity,feature,location,action}]}`.
  - Создать синтетический корпус `tests/corpus/docx/`: D1 (body/table: widths, header row, 2×2 merge, bold/link), D2 (2 секции, default/first/even headers/footers, inheritance, page break), заготовки D3 (discussions) и D4 (revisions).
- В `packages/vue`: добавить локальный экспорт подтверждённой доступной версии с выбором профиля и preview предупреждений.
- **Проверка:** `DOCX` roundtrip (D1–D2, затем D3–D4 после `8.1`/`8.2`), браузерный экспорт с fetch trap (0 сетевых запросов) и равенство semantic report с Node.

### Шаг 3 (`8.2`) — GPT-6.2 Sol → ревью Astra
- В `packages/review`:
  - Создать миграцию `v_document_suggestions` по `plan/spec/database/sql/editor-pro-review.sql`.
  - Реализовать версионированные операции `insert`/`delete`/`replace`, явную `group_id` (для пар replacement), авторство строго из доверенного сеанса, связь с `proposal_id` и атомарный accept/reject через `AtomicDocumentMutation` (для snapshot).
- В `packages/vue`:
  - Реализовать Tiptap OSS/ProseMirror plugin review overlay: перехват локальной транзакции до публикации (remote replay не переотслеживается), три явные проекции (`original`, `proposed`, `review`), выбор отдельных предложений и групп, корректная работа undo и IME.
- Гарантировать, что обычный Office SDK read продолжает читать только `original` (принятый текст).
- **Проверка:** сценарий `REVIEW` (два автора, независимые и пересекающиеся предложения, P/Q, повтор accept, конфликт второго, отзыв прав, отсутствие retrack при replay).

### Шаг 4 (`10.2`) — Gemini Neon → ревью Astra
- В `packages/exchange` (`axiomasoft/vaulter-editor-pro-exchange`):
  - Создать миграцию `v_document_exchange_jobs` по `plan/spec/database/sql/editor-pro-exchange.sql`.
  - Реализовать очередь с lease/fencing, удержание исходного Media blob, лимиты распаковки/entries и отключение external rels / XXE до разбора архива.
  - Реализовать маршруты `POST /documents/{id}/exchange-jobs`, `GET …/{job}`, `GET …/{job}/file` (повторный ACL View/export/history) и `POST …/{job}/apply` (атомарное применение через `AtomicDocumentMutation` в snapshot или `CommandJournal` в journal с отдельным `command_id` и CAS головы).
- В `packages/vue`: UI загрузки DOCX, статуса задания после reconnect, просмотра отчёта потерь и явного подтверждения `apply`.
- **Проверка:** сценарий `FILES` (roundtrip, kill worker после записи Media до ссылки + GC orphan, повтор apply и потеря ответа после owner commit, устаревший preview, битый архив / external rel, отзыв прав перед скачиванием и apply).

### Шаг 5 (`8.3`) — Gemini Neon → ревью GPT-6.2 Sol
- Расширить `packages/review` и `packages/vue` профилями `mark_change`, `attr_change`, `block_split`, `block_join`, `list_change`, `table_change` (текстовые таблицы).
- Подключить accept/reject к `vaulter-collaboration` `CommandJournal` для режима strict journal (`3.5`).
- Зафиксировать политику пересечений v1 (второе предложение остаётся `open` с явным конфликтом и сохранением исходного автора), скрывать неподдерживаемые типы по capability в UI и отклонять их в API с откатом кандидата.
- **Проверка:** `REVIEW` по каждому разрешённому виду в snapshot и strict journal + откат неподдерживаемого типа.
