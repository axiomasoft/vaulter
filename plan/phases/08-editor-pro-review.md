# 8. История, Range Comments и Tracked Changes (vaulter-editor-pro)

[Паспорт `vaulter-editor-pro`](../repos/vaulter-editor-pro.md) · [План разработки](../dev-plans/vaulter-editor-pro.md) · [Спецификация](../spec/implementation/editor-pro.md) · [Схема БД](../spec/database/editor-pro.md) · [DDL review](../spec/database/sql/editor-pro-review.sql).

### 8.1 История preview/compare и Range Comments

**После** — 1.0, 3.1.

1. В `vaulter-editor-pro/packages/review` (`axiomasoft/vaulter-editor-pro-review`) реализовать миграции `v_document_revision_pins` и `v_document_comment_anchors` по [DDL](../spec/database/sql/editor-pro-review.sql). Неизменяемые revision refs удерживаются при compaction.
2. Расширить существующие `v_node_comments` generation-scoped диапазонными якорями (`snapshot_utf16` для snapshot и `yjs_relative` для journal с `assoc = 0` на левой и `assoc = -1` на правой границе, плюс хелпер `safeResolveRelativePos` против бага `@tiptap/y-tiptap #8614` на `pos === 1`), сохраняя прежние комментарии узла без range. Поддержать CAS resolve/reopen, replies, reanchor, restore-detach и канонический resolver при частичном/полном удалении цели.
3. В `packages/vue` (`@vaulter/editor-pro-vue`, `@tiptap/core >= 3.34.0`) реализовать read-only preview/compare (двухэтапный diff на `prosemirror-changeset >= 2.4.4`) в отдельном `EditorView` (ноль live updates) и sidebar обсуждений (active/resolved/detached, восстановление привязки, индикатор актуальности).

- **8.1-A** При сдвиге/частичной правке якорь корректно сужается, при удалении первого символа перед комментарием (`pos === 1`, `#8614`) не возвращает `null`, ввод вплотную снаружи границ не расширяет диапазон, при удалении цели переходит в `detached` без потери replies; `reanchor` восстанавливает привязку. Restore переводит старые якоря в `detached/historical`. Подмена автора через Yjs невозможна.
- **8.1-B** Preview и structural compare отображают marks/attrs двух неподвижных версий и создают ноль live updates; после compaction закреплённая ревизия остаётся доступной по текущему ACL.

### 8.2 Текстовый Tracked Changes и атомарный accept/reject

**После** — 3.3, 8.1.

1. В `packages/review` реализовать миграцию и сервис `v_document_suggestions` (insert/delete/replace, явная `group_id`, автор из сеанса, связь с `proposal_id`). Accept/reject выполняются атомарно через `AtomicDocumentMutation` (для snapshot) и, после 3.5, через `CommandJournal` (для journal).
2. В `packages/vue` реализовать Tiptap OSS/ProseMirror review overlay на `@tiptap/core >= 3.34.0`: перехват локального ввода строго через хук `dispatchTransaction({ transaction, next })` до публикации в Yjs (запрет `appendTransaction`/`filterTransaction`, bypass по `ySyncPluginKey`/`y-undo`/`history`/`vaulter:untracked`), буферизацию IME между `compositionstart` и `compositionend`, группировку строго по смежности, три явные проекции (`original`, `proposed`, `review`), выбор отдельных предложений и групп replacement, и каскадный reject вложенных правок (`parentId`).
3. Обычное чтение Office SDK по умолчанию продолжает читать только принятое (`original`) содержимое; неподтверждённые предложения не попадают в канонический текст.

- **8.2-A** Два автора создают независимые и пересекающиеся suggestions; accept одной группы атомарно меняет canonical и выдаёт квитанцию, повтор возвращает тот же исход, reject не меняет документ. Пересекающееся предложение остаётся открытым для нового решения.
- **8.2-B** Remote replay не создаёт локальных предложений; undo и IME работают предсказуемо на текстовом корпусе; отзыв прав блокирует accept/reject.

### 8.3 Структурный review и политика пересечений

**После** — 3.5, 8.2.

1. Расширить контракты `v_document_suggestions` и review overlay отдельными поддерживаемыми профилями: `mark_change`, `attr_change`, `block_split`, `block_join`, `list_change` и `table_change` (текстовые таблицы).
2. Для пересекающихся структурных предложений v1 закрепляет явную политику: второе предложение остаётся неподтверждённым с явным конфликтом и сохранением исходного автора.
3. Неподдерживаемые структуры скрываются по capability в UI, а API отклоняет операцию до изменения документа с откатом кандидата.

- **8.3-A** Каждый заявленный структурный вид (`mark/attr/split/join/list/table`) проходит accept/reject в snapshot и strict journal с сохранением целостности дерева и авторства.
- **8.3-B** Неподдерживаемая структура и пересекающийся конфликт отклоняются до записи без частичного изменения документа.
