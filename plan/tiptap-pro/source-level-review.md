# Разбор установленных Provider, Comments, Snapshot и Snapshot Compare

Дата: **2026-11-21**. Здесь описаны реально полученные npm-пакеты, их клиентские структуры и алгоритмы, а затем — отдельный проект собственного backend. Это не описание исходников hosted-сервера: серверные SQL, очереди, storage engine и граница durable commit из клиентского bundle не восстанавливаются.

Обозначения доказательств:

- **Source** — прочитаны установленный `dist/index.js`, `dist/index.d.ts` и включённый `docs/api.md`; ссылка указывает пакет и метод/символ, а не предположение по названию кнопки.
- **Local** — выполнен [source-state-probe.mjs](scripts/source-state-probe.mjs), результат [source-state-probe.json](results/source-state-probe.json): **19 проверок PASS**, синтетическое состояние, auto-connect отключён, Cloud-запросов нет.
- **Cloud** — отдельный [сценарий Comments/Versions](results/cloud-comments-versions-2026-11-21T08-30-54-361Z.json): девять основных проверок прошли, общий результат `partial`, потому что свежий reader не завершил подключение. Этот документ не превращает partial-run в полную приёмку persistence.
- **Design** — предлагаемое решение Vaulter, не установленный факт о Tiptap.

## 1. Точные артефакты и точки входа

Все четыре пакета имеют версию **3.12.9**. Пути ниже относительны `plan/tiptap-pro/`; SHA256 относится к исходному установленному `dist/index.js`, а не к локально отформатированной копии.

| Пакет / путь | SHA256 | Проверенные точки входа |
| --- | --- | --- |
| `lab/node_modules/@tiptap-pro/provider/dist/index.js` | `598d33393e3e3fb7808f4ef49ff792687b76584d9ae818852e425a4a1de9e149` | `TiptapCollabProvider`, `getYThreads`, `createThread`, `addComment`, `deleteComment`, `listVersions`, `lastSavedHandler`, `setFieldType` |
| `lab/node_modules/@tiptap-pro/extension-comments/dist/index.js` | `518f8c8e1a93021065bb8569ff608fc4f71a206a41490ac9347a116564194b4b` | `Comments.addCommands`, `Comments.onTransaction`, `getThreads`, `subscribeToThreads`, `InlineThread`, `BlockThread`, внутренние `Ye`/`Ze`/`q` |
| `lab/node_modules/@tiptap-pro/extension-snapshot/dist/index.js` | `b2d89471e64c407cb4cd111a3eca54ddbfecd0642d95560f8ea1a9d13295fe13` | `Snapshot.onCreate`, `Snapshot.addCommands`, `getPreviewContentFromVersionPayload`, `watchPreviewContent` |
| `lab/node_modules/@tiptap-pro/extension-snapshot-compare/dist/index.js` | `9866bdd53cfc4a42ab75ea8a4301966a851a383fd46d8a9a928e1915ea26fbe1` | `getVersions`, `getChangesetTransform`, `ChangeTrackingStep`, `ChangeTrackingTransform`, `changesToDiff`, `defaultMapDiffToDecorations`, `SnapshotCompare.addCommands` |

В зависимости реально используются Hocuspocus provider **4.9.0**, Tiptap core/pm **3.35.0**, y-tiptap **3.3.1**, Yjs **13.6.33**. В исследованном дереве каждая из этих библиотек представлена одной версией. Подробная [инвентаризация](lab/pro-inventory.md) отделяет установку от entitlement конкретной backend-функции.

Vendor bundles и source maps не включаются в отчёт или Git. Ниже — описание своими словами, наблюдаемые имена/форматы и короткий собственный псевдокод.

## 2. Фактическое состояние Y.Doc

**Source:** `provider: TiptapCollabProvider.constructor/getYThreads/setFieldType`; `comments: Ye.shared/save/getSharedRanges`; Yjs `PermanentUserData.setUserMapping`.

| Shared type | Тип / содержимое | Назначение |
| --- | --- | --- |
| `default` или выбранный field | `Y.XmlFragment`, элементы rich text и форматированные `Y.XmlText` | Содержимое редактора, inline/block anchors |
| `__tiptapcollab__threads` | `Y.Array<Y.Map>` | Обсуждения, comments, состояние resolve/archive |
| `__tiptapcollab__users` | `Y.Map`, используемая `Y.PermanentUserData` | Соответствие Yjs client IDs пользователям и delete sets для attribution |
| `__tiptapcollab__config` | `Y.Map` | `autoVersioning`, `intervalSeconds`, `currentVersion`, `lastSaved`, `versionsV1Migrated` |
| `__tiptapcollab__versions` | `Y.Array` в протоколе истории v1 | Список метаданных версий; в v2 список приходит stateless и кешируется у provider |
| `__tiptapcollab__typemap` | `Y.Map<fieldName,typeName>` | Декларация типа именованного поля: map/array/text/xmlfragment/xmlelement |
| `__tiptapcollab__resolvedThreadAnchors` | `Y.Map<threadId,{field,ranges}>` | Сохранённые anchor ranges для восстановления после скрытия/изменений; границы кодируются Yjs relative positions |

Последние две карты могут отсутствовать, пока соответствующая операция не выполнялась. `ydoc.share` — наблюдаемый набор реально созданных контейнеров, а не полный каталог возможностей пакета.

`PermanentUserData` хранит на пользователя nested `Y.Map` с `ids: Y.Array` и `ds: Y.Array` кодированных delete sets. Provider передаёт ему строку `user` из конфигурации и регистрирует связь после sync. Это данные attribution. Они не превращают браузерную строку автора в авторитетную identity сервера.

**Local:** probe подтвердил Y.Array обсуждений, вложенные Y.Map thread/comment, отдельные Y.Array `comments`/`deletedComments`, config и обе ветки списка версий. Карта resolved anchors и typemap установлены по source; локальный probe не имитирует полноценный DOM/Comments lifecycle для этих веток.

## 3. Comments: записи, anchors и путь изменения

### 3.1. Thread и comment

**Source:** `provider: createThread/updateThread/deleteThread/restoreThread/addComment/updateComment/deleteComment`.

Thread — отдельная Y.Map с полями:

| Поле | Значение |
| --- | --- |
| `id` | UUID, генерируемый клиентом |
| `createdAt`, `updatedAt` | ISO timestamp клиентского времени |
| `data` | Прикладные metadata; передаются как значение, без обязательной внутренней схемы |
| `field` | Необязательное имя rich-text field |
| `resolvedAt` | Необязательный ISO timestamp или `null` |
| `deletedAt` | `null` или timestamp архивирования |
| `comments` | Y.Array активных Y.Map comments |
| `deletedComments` | Y.Array tombstone-records удалённых comments |

Активный comment содержит `id`, `createdAt`, `updatedAt`, `data`, `content`. Tombstone дополнительно получает `deletedAt`. `content` — прикладное значение обсуждения, не fragment основного Tiptap-документа. В probe это обычная синтетическая строка.

Provider обновляет metadata заменой значения `data`, а не рекурсивным merge. `updateComment` в этой версии присваивает `content` только при truthy значении; очистить его пустой строкой этим путём нельзя. Это особенность реализации клиента, не требование к собственной API.

### 3.2. CRUD не является набором REST-вызовов

**Source + Local:** `createThread`, `addComment`, `updateComment` изменяют Y.Doc; `watchThreads` подписывается через `observeDeep`. Вызовов `sendStateless` в этих CRUD-путях нет. Обычный Hocuspocus provider затем распространяет Yjs updates. Наш probe перехватил stateless sender и подтвердил ноль history/stateless actions во время mutations обсуждения.

У `Comments.commands.setThread` есть несколько эффектов: создать thread через provider, добавить первый comment, затем поставить mark или wrapper через ProseMirror transaction. В source это не одна общая Y.Doc transaction. В Local создание thread и первого comment действительно породили отдельные updates. Поэтому один boolean результата Tiptap-команды не устанавливает атомарную backend-операцию «запись обсуждения + первый comment + anchor».

Псевдокод наблюдаемого разделения, без воспроизведения реализации:

```text
создать запись обсуждения в общем CRDT
добавить к обсуждению начальный comment
пометить выделенный rich-text диапазон идентификатором обсуждения
обычный transport доставляет получившиеся CRDT updates
```

Это объясняет, почему серверная REST-операция создания discussion сама по себе не прикрепляет его к тексту: запись и anchor находятся в разных частях общего состояния.

### 3.3. Anchor identity и диапазоны

**Source:** `InlineThread`, `BlockThread`, `Comments.addCommands.setThread`, `getThreads`, `comments__positions`.

Inline anchor — mark `inlineThread` с `data-thread-id`. Он non-inclusive, сохраняется при split и допускает пересекающиеся marks. Block anchor — node `blockThread` с тем же ID. По умолчанию wrapper содержит один block; deprecated legacy-mode разрешает `block+`. HTML использует соответственно `span[data-inline-thread]` и `div[data-block-thread]`.

Числовые `from/to` не являются первичным хранилищем обычного anchor. Плагин строит диапазоны из marks/wrappers текущего ProseMirror document, переносит неизменившиеся диапазоны через transaction mapping и пересчитывает затронутые области. Query `getThreads({editor,provider})` соединяет discussion records с этими ranges. У detached thread массив ranges пуст. Один thread может иметь несколько отдельных ranges.

Фильтр `field` включает записи без field для обратной совместимости. Это клиентский фильтр, не доказательство server ACL между полями.

### 3.4. Resolve, archive, удаление текста и undo

**Source:** `Comments.addCommands.resolveThread/unresolveThread`, внутренний step `q` (`commentThreadHistory`), обработчик транзакций `$e`, `Ye` и `Ze`.

Resolve записывает history-step с прежним и новым resolvedAt; обработчик редакторской транзакции применяет его к provider. Обычные anchors остаются в документе и получают CSS-состояние resolved. Reopen ставит `resolvedAt=null` и при необходимости восстанавливает anchor.

Для исчезнувших anchors используется `__tiptapcollab__resolvedThreadAnchors`: на thread сохраняются field и типы ranges, а начало/конец — encoded Yjs relative positions. При reopen они переводятся в актуальные абсолютные позиции с учётом текущего binding. Если диапазон не восстанавливается, команда не обязана успешно прикрепить обсуждение.

Undo resolution не полагается только на rich-text mark. Пакет дополнительно связывает transitions resolvedAt с Yjs UndoManager stack-item metadata; без Yjs использует собственный ProseMirror history-step. Это отдельная часть поведения, которую простая реализация «поменять timestamp в SQL» не воспроизведёт.

Archive по умолчанию ставит `deletedAt`, но сохраняет comments. `getThreads()` скрывает архивные записи; восстановление возвращает тот же ID и comments. `force` удаляет элемент из Y.Array; это не обещание физического стирания всех исторических CRDT-данных. При удалении reply создаётся tombstone-record в `deletedComments`; `deleteContent=false` сохраняет текст. Удаление первого comment с `deleteThread=true` может вместо перемещения reply архивировать thread.

При полном исчезновении anchor cleanup работает с debounce **350 ms**, текущим набором threads и признаком синхронизации. Remote Yjs transaction распознаётся отдельно, чтобы каждый клиент не порождал новое конкурирующее cleanup-решение. Есть hooks, позволяющие заменить default anchor/state handling. Paste/drop удаляет неподходящие ссылки, например ID обсуждения, отсутствующего у provider. Перенос пользовательского HTML не переносит автоматически весь discussion.

### 3.5. Интеграция с Tracked Changes

**Source:** comments plugin `commentsTrackedChangesIntegration`, callbacks `trackedChanges:suggestion*`.

Discussion может принадлежать suggestion. В его data сохраняются `source='suggestion'`, suggestionId/type/text, reason, mark-change metadata и user metadata. Текст replacement берётся из вставляемой части; вложенный чужой insertion не должен ошибочно стать текстом внешнего предложения. Nodes могут получать wrappers, inline proposals — marks.

Accept обычно resolve-ит discussion и снимает его anchors; reject удаляет discussion/anchors; повторное появление suggestion может reopen/restore обсуждение. Пакет использует loop guards, проверку remote origins и отложенные handlers. Эти поля описывают review UI. Они не заменяют trusted approval, sealed proposal digest или receipt Office SDK.

## 4. Versions и preview: фактический клиентский протокол

**Source:** `provider: constructor/listVersions/createVersion/revertToVersion/previewVersion`; `Snapshot.onCreate/addCommands`.

У provider две ветки истории. В v1 `listVersions()` читает Y.Array `__tiptapcollab__versions`. После sync он проверяет `config.versionsV1Migrated===1`; при этом выбирает v2, где список приходит stateless-сообщением и хранится в обычном `_versions` cache.

| Запрос через `sendStateless(JSON.stringify(...))` | Ключевые поля |
| --- | --- |
| `versions.list` | action |
| `version.create` | name, force, необязательный meta |
| `version.preview` | version |
| `document.revert` | version, fields (по умолчанию `['default']`), необязательные currentVersionName/newVersionName |

Входящие event-пути: `versions.list` с массивом версий; `version.created` с version/name/date/meta; `document.reverted` с version; `version.preview` с version и base64 `ydoc`; `document.saved` с date у provider.

Обычная запись версии в UI содержит номер, дату, необязательные имя и meta. Она не содержит сам snapshot payload. Preview — отдельный запрос полного состояния. Auto-versioning управляется изменениями config-map (`autoVersioning` 1/0, `intervalSeconds`), а не отдельным client REST endpoint.

**Source + Local:** `getPreviewContentFromVersionPayload` декодирует base64 как **Yjs update V1**, применяет его к новому Y.Doc и получает JSON выбранного field. Live document эта функция не изменяет. В JSON нормализуются suffixes многократных mark-инстансов, используемых для overlapping marks.

У provider list v2 нет встроенного response timeout или request ID; ожидание сопоставляется по event. Свой protocol должен добавить correlation/deadline. Snapshot extension возвращает boolean принятия команды, а не server receipt. Параметры `false` для названий копий при restore прямо поддержаны provider; extension в этой версии подставляет generated title через truthy-fallback, поэтому его wrapper не полностью эквивалентен прямому provider API.

Ещё одна деталь source: provider обрабатывает `document.saved`, а Snapshot storage имеет case `saved`. Нельзя автоматически приравнивать оба UI-поля lastSaved и тем более выводить из них SQL durability. Реальный commit-контракт Vaulter уже определён отдельно.

**Cloud:** в указанном run созданы версии, изолированный baseline preview содержит 15, пока оба live клиента содержат 30; restore вернул обоим 15. Состояние discussion после restore снято отдельно. Новый reader остался незавершённой проверкой. Внутренний способ хранения версии на Cloud из этого не следует.

## 5. Snapshot Compare: diff вычисляется на клиенте

**Source:** `snapshot-compare: getVersions/getChangesetTransform/ChangeTrackingTransform/changesToDiff/defaultMapDiffToDecorations`.

### 5.1. Откуда берутся две версии

Для сравнения по номерам helper читает cached list, запрашивает два `version.preview`, сопоставляет ответы по version, декодирует base64 V1 updates и конвертирует их в update V2. Ожидание ограничено десятью секундами. Можно передать два готовых V2 updates напрямую и вообще не использовать provider.

Следовательно, в исследованном коде нет обязательного server endpoint «сравни два текста». Backend поставляет историческое CRDT-состояние, а diff/attribution/decorations вычисляются в браузере. Default `toVersion` этой версии helper выводит из длины cache и затем ищет запись по номеру; для неплотной истории лучше задавать номера явно.

### 5.2. Восстановление изменений

Алгоритм создаёт временный **Y.Doc({gc:false})**, применяет старый V2 update, фиксирует snapshot и начальное ProseMirror-состояние; затем применяет новый update и фиксирует второй snapshot. Он обходит Yjs-дерево, сравнивая видимость элементов по state vectors и delete sets. Идентичность `clientID/clock` позволяет отличать существующий элемент от добавленного/удалённого.

Для текста анализируются delta spans и marks; для nodes — видимость и attrs. Формируются replacement steps с attribution, а не только строки со знаком плюс/минус. Пользователь additions определяется по client ID, удалений — через delete sets PermanentUserData. `hydrateUserData` добавляет display metadata, но не выполняет авторизацию.

Adjacent совместимые steps упрощаются; proposal-only различия не превращаются автоматически в содержательные правки. Defaults игнорируют `inlineThread` marks и `blockThread` nodes, чтобы review anchors не засоряли diff. Реализация обращается также к внутренним полям Yjs (`_item`, `_map`, `_start`), поэтому буквальное повторение этого механизма создаст жёсткую связь с версией Yjs.

### 5.3. Diff-модель и отображение

Из transform получаются `inline-insert/delete/update` и `block-insert/delete/update`. Дополнительно различаются content, mark-only и attribute-only изменения. Attribute diff несёт прежние и новые attrs. Общие префиксы/суффиксы соседних delete+insert одного автора могут обрезаться для более компактного отображения.

Decorations используют:

- widget для удалённого содержимого, которого уже нет в новом документе;
- inline/node decoration для вставок;
- node decoration с old/new attributes для изменений атрибутов;
- отдельные визуальные line-break markers в подходящих block-ситуациях.

**Local:** probe взял одну Y.Doc, заменил `15` на `30`, сохранил bold и второй paragraph. `getChangesetTransform` вернул правильный итоговый JSON, а diff — inline-delete `15` и inline-insert `30` с attribution синтетического автора. Соседний paragraph остался неизменным.

### 5.4. Preview не должен случайно стать записью

В source `showDiff` сохраняет previousContent и выполняет `setContent` на переданном editor с metadata `addToHistory=false`, `trackedChanges:skipTracking`. Это не универсальное обещание «ничего не попадёт в Yjs»: отключение undo/recording suggestions не равно выключению collaboration binding. Самостоятельная утилита `renderDiff` строит отдельный EditorView с запрещёнными document transactions.

**Design:** собственный diff показывать в отдельной read-only view/копии. Если использовать live editor, потребуется отдельно доказать, что preview не порождает journal updates. Не копировать `setContent` как безопасный default только потому, что это называется preview.

## 6. Собственная реализация: сущности и операции

Это **Design**, расширяющий [comments-versions-design.md](comments-versions-design.md), [tracked-changes-design.md](tracked-changes-design.md) и действующий [контракт owner/receipt](../spec/collaboration.md). Имена таблиц ниже — предлагаемые additions; они не являются восстановленной SQL-схемой Tiptap.

### 6.1. Не создавать второй журнал записи

Уже запланированные `v_collaboration_heads`, `v_collaboration_updates`, `v_collaboration_receipts`, `v_collaboration_commands` остаются источником порядка, fencing, idempotency и durable outcome. CRDT discussion data можно хранить рядом с rich text в документе, используя собственные имена shared types. SQL discussion/comment tables тогда являются проекциями подтверждённого префикса, а не независимой второй истиной.

| Предлагаемая сущность | Минимальные данные | Источник истины / связь |
| --- | --- | --- |
| discussion projection | document, generation, discussion ID, field, data, resolved/archived timestamps, projection_seq | Подтверждённый CRDT-префикс |
| comment projection | discussion ID, comment ID, content/data, created/edited/deleted, authenticated actor, projection_seq | Подтверждённый префикс и серверный actor; не строка user из браузера |
| anchor projection | discussion ID, field, blockId, ranges/relative-position evidence, generation, projection_seq | Текущие anchors rich text; абсолютные offsets пересчитываются |
| immutable history record | revision ID, document/generation, captured head_seq, binary checkpoint reference/hash, schema profile, creator, name/meta | Существующая модель revisions + удержанный бинарный checkpoint/log |
| review operation result | command ID, effect type, обсуждение/версия/suggestion, receipt ID | `v_collaboration_commands` и receipts; не новая таблица «ACK успех» |

Metadata вроде resolvedAt и author display name не должны заменять серверную audit identity. Для comment-only пользователя собственная API принимает семантические операции обсуждения у owner; unrestricted binary write на всю Y.Doc нельзя считать эквивалентом «разрешено только комментировать» без отдельной проверки допустимых изменений.

### 6.2. Один semantic command — один подтверждённый исход

Собственный псевдокод, отличающийся от нескольких независимых клиентских mutations:

```text
получить команду с прежним command_id, document, generation и expected marker
проверить текущий доступ и направить команду сохранённому writer/owner
восстановить подтверждённый префикс, проверить epoch/schema/цель
в scratch Y.Doc подготовить discussion + comment + anchor как единый эффект
в транзакции владельца зафиксировать точные update bytes, head, receipt и outbox
после commit применить эти же bytes в комнате и обновить проекции
при retry вернуть исход прежней команды; не генерировать новый discussion/comment ID
```

Операции: `discussion.create/reply/editComment/archive/reopen/resolve`, `anchor.detach/reattach`, `version.capture/list/preview/restore`, `suggestion.accept/reject`. Наличие успешного thread-ID или boolean Tiptap-команды — только локальная подготовка. Пользовательский результат `committed` должен приходить из receipt владельца.

Для обсуждения на text range предусловие включает актуальные generation, field, block identity, ожидаемый форматированный фрагмент или относительные позиции. Author ID берётся из effective subject, а клиентские timestamps остаются display metadata. Отказ на середине compound-effect не должен оставлять единственный comment без anchor при обещанной атомарности команды.

### 6.3. History и restore

Для preview достаточно восстановить отдельную историческую Y.Doc и вернуть разрешённый fragment/JSON. Это read, не writer route. Для full attribution и Yjs-based compare нужно удержать исходные identities и удалённые structs: одного JSON или Yjs snapshot descriptor со state vector/delete set недостаточно после очистки нужных данных.

Простое `applyUpdate(oldSnapshot)` поверх новой Y.Doc не является restore: merge старого update не отменяет более новые CRDT-операции. В нашем плане restore повышает generation и ограждает прежние комнаты. Следует явно выбрать восстанавливаемые fields и судьбу discussions/anchors; при сохранении текущих discussions проверить/переадресовать anchors относительно новой текстовой основы. Старый `(generation,position)` не должен незаметно стать новым marker.

History capture должен ссылаться на зафиксированный head_seq. Название версии и номер из sidebar не служат receipt. После отставания проекции авторитетное чтение восстанавливает checkpoint + хвост до нужной позиции либо возвращает pending.

### 6.4. Независимый diff без зависимости от Pro

Не обязательно воспроизводить внутренние классы ChangeTrackingTransform. Для первой собственной реализации можно сравнить два разрешённых ProseMirror JSON с одинаковым schema profile: сопоставить стабильные block IDs, выполнить diff runs внутри изменённых blocks, отдельно сравнить marks и attrs, затем отобразить результат read-only decorations. Attribution добавлять из подтверждённого журнала/доказательств, а неизвестного автора показывать явно. Перемещение block и вложенные review marks требуют отдельных сценариев.

Тесты поведения: `15→30` с сохранением bold и соседнего p71; обсуждение с несколькими anchors; reply/resolve/reopen/удаление anchor; concurrent edit до/внутри anchor; restore выбранного field без молчаливой потери discussions; accept/reject из второго клиента; неизвестная schema; retry после commit-before-response. Равенство байтов с bundle не требуется. Требуются одинаковые пользовательские постусловия и собственные доказанные commit-гарантии.

## 7. Что источник не доказал

Клиентские structures и передаваемые команды установлены. Внутренние таблицы Cloud, atomicity между thread и anchor на сервере, сохранность при process crash, распределённые блокировки, retention и compaction, enforcement comment-only прав внутри бинарных updates остаются неизвестными. Наличие `document.saved`, SyncStatus, version.created или успешного reload не заполняет эти пробелы автоматически.

Основной практический вывод: значительная часть Pro Review — клиентский schema/plugin/UI над Yjs, а Versions требует небольшого явного history-протокола с историческими binary states. Собственная реализация может опираться на OSS Tiptap/Yjs/Hocuspocus и уже предусмотренный Vaulter owner/receipt, сохранив независимый формат данных и проверяемые доменные гарантии.

## 8. Дополнительные Pro-модули: установка и source-слои

Отдельный серийный проход проверил 14 дополнительных package names из первичной документации. Результаты и SHA256 каждого доступного bundle находятся в [additional-pro-source-inventory.json](results/additional-pro-source-inventory.json). В main они не импортировались; layout и Conversion runtime этим проходом не проверены, платные service calls не выполнялись.

| Пакет | Фактический результат | Что прочитано / граница проверки |
| --- | --- | --- |
| Pages | Direct latest 0.31.0: ERESOLVE; пакет дополнительно скачан разрешённым `npm pack` в ignored artifacts; также доступен nested у PageKit | Source-only разбор `Pages` и layout scheduler; не успешная регистрация Pages с корневым ConvertKit |
| Pages TableKit | Установлен 0.4.8 | NodeViews, table attrs, grid и pagination integrations |
| PageKit | Установлен 0.1.21 | Composition extension; приводит nested Pages 0.31.0 |
| PageBreak | Уже установлен transitive 0.3.6 | Node schema, insertion command, Pages-mode view |
| PasteHandler | Установлен 3.12.9 | HTML normalization pipeline и Slice transform |
| Content Protection | HTTP 403 | Source недоступен; реализация не утверждается |
| Slot | HTTP 403 | Source недоступен |
| Variable | HTTP 403 | Source недоступен |
| Diagrams | HTTP 403 | Source недоступен |
| Whiteboard | HTTP 403 | Source недоступен |
| Export DOC / PDF / ODT | Установлены по 0.7.4 | Commands и обе ветки request serialization |
| Export EPUB | Установлен 0.5.4 | Command и JSON/DOCX upload paths |
| Export Markdown | Установлен 0.2.8 | Локальный getMarkdown callback, без сетевого вызова в wrapper |

### 8.1. Pages, TableKit, PageKit, PageBreak

**Source:** Pages 0.31.0 (`dist/index.js: Pages.addStorage/addCommands/addProseMirrorPlugins`, layout scheduler, `Pr` config binding); TableKit 0.4.8 (`TableKit`, table cell/header extensions и managed grid NodeView); PageKit 0.1.21 (`PageKit.addExtensions`); PageBreak 0.3.6 (`PageBreak.addNodeView/insertPageBreak`).

Pages — клиентская layout-система. Она измеряет DOM, планирует пересчёты через requestAnimationFrame, отслеживает размеры через ResizeObserver, строит pagination decorations и отображает header/footer/notes в дополнительных editor views. В исследованном Pages bundle нет прямого `fetch` или `sendStateless`. Это не требует специального «backend пагинации», но совместный документ всё равно требует хранения его содержимого и конфигурации.

При collaboration Pages связывает `__tiptapcollab__pages_config` с Y.Map. Source показывает pageFormat/pageGap, разные первые/чётные/нечётные headers/footers и margins; начальные значения записываются после sync, только когда соответствующих ключей ещё нет. Не следует заменять это безусловным seed каждой вкладки. Headers/footers и notes имеют собственные editor/lifecycle paths, поэтому копирование одного основного rich-text JSON не доказывает сохранение всей Pages-модели.

TableKit расширяет ConvertKit table/cell/header/row, сохраняет DOCX-aware attributes и рассчитывает CSS grid widths, colspan/rowspan и visual continuation merged cells. Есть minimum-width floors, resize handling, overflow marker и opt-in experimental row fragmentation. Его вычисления работают с геометрией редактора. Считать каждое визуальное продолжение новой SQL-строкой или новым логическим paragraph было бы ошибкой: необходимо отдельно различать logical table и layout representation.

PageKit не включает ConvertKit. По source он всегда добавляет Pages, по умолчанию добавляет PageBreak, а TableKit — только при truthy `table` options. PageBreak — block atom, `Mod-Enter` вставляет его и обеспечивает место для следующего textblock. В Pages-mode node view вычисляет заполняющую высоту до footer с поправкой на zoom, вне Pages-mode показывает обычный разделитель.

Согласованность профиля: Pages 0.31.0 требует **точный ConvertKit 0.15.1**; корневой lab использует 0.16.1. TableKit 0.4.8 требует точный ConvertKit 0.16.1. Direct Pages install поэтому завершился peer conflict. PageKit установил nested Pages с отдельным dependency profile; это не доказательство совместимости всего набора, зарегистрированного в одном editor. Общий lab не понижался, `--force` не применялся. Для собственного продукта pagination и schema-fidelity должны иметь отдельную закреплённую compatibility matrix.

### 8.2. PasteHandler

**Source:** 3.12.9, `PasteHandler.addProseMirrorPlugins`, exports `transformPastedHTML`, CSS/list/table cleanup helpers.

Плагин перехватывает `transformPastedHTML` и `transformPasted`. До schema parse он разворачивает supported CSS classes в inline styles, нормализует Word `mso-*`/bookmarks, border dividers, table column widths и cell text styles, highlights, ARIA headings, soft breaks и списки. Второй hook преобразует полученный Slice с учётом selection. Сетевой слой в этом wrapper отсутствует.

Для независимой реализации нужен набор HTML fixtures из Word/Google Docs с assertions по итоговому schema JSON. Сохранение вида в одном browser не доказывает корректную нормализацию nested lists, rowspan/colspan или mixed formatting. Это отдельный import pipeline, а не новая collaboration/backend сущность.

### 8.3. Export DOC, PDF, ODT, EPUB, Markdown

**Source:** соответствующие `ExportDoc/ExportPdf/ExportOdt/ExportEpub/ExportMarkdown.addCommands`, helper `extension-convert-kit/convert-endpoint`.

DOC/PDF/ODT/EPUB wrappers имеют service paths `/export/doc`, `/export/pdf`, `/export/odt`, `/export/epub` относительно выбранного Conversion endpoint. Default v2 endpoints различают EU/US. Headers содержат Bearer JWT, `X-App-Id`, `X-Tiptap-Extension`. Эти имена установлены по source; никаких действительных token values в отчёт не включается.

Предусмотрены две схемы передачи:

- Если editor располагает export-docx командой/extension, сначала получается DOCX Blob, затем multipart FormData с полем `docxFile` отправляется Conversion service. PDF дополнительно умеет передать custom fonts.
- Другая ветка сериализует `editor.getJSON()` в строковое `doc` внутри JSON request и добавляет выбранные layout/style options. Headers/footers могут извлекаться из Pages storage. Наличие ветки в source не означает её успешную доступность при любой композиции extensions: некоторые wrappers предварительно проверяют exportDocx capability.

Ответ обрабатывается как Blob и передаётся `onCompleteExport`. PDF команду завершает boolean-ответом до окончания async работы, поэтому completion/error callbacks важнее мгновенного boolean. DOC/ODT/EPUB также не следует интерпретировать как durable write документа: это file conversion path.

Export Markdown 0.2.8 устроен иначе: требует зарегистрированный OSS `@tiptap/markdown`, вызывает `editor.getMarkdown()` и callback. В его source нет `fetch` или JWT-проверки. Нельзя переносить модель платного server conversion на этот конкретный wrapper только по названию семейства.

**Design:** собственный Conversion backend — отдельный versioned job/file pipeline: фиксированный input revision/marker, schema/asset/font manifest, output format/options, request ID, status и hash результата. Он не должен получать admin token collaboration и не должен подменять receipt редактирования. Для форматов, требующих server renderer/converter, source frontend не раскрывает серверный движок и не позволяет обещать идентичную fidelity без round-trip fixtures. Markdown можно реализовать локальным serializer, а Pages — независимым клиентским layout layer.

### 8.4. Проверка в браузере

На версиях из lab shrinkwrap прошли девять сценариев и 40 проверок без внешних запросов. PasteHandler через `editor.view.pasteHTML` преобразовал ARIA-заголовок в `heading` уровня 2, сохранил bold и вложил плоский список Word. Markdown-экспорт совпал с `editor.getMarkdown()`.

PageKit с выключенными таблицами выполнил `insertPageBreak` с двумя схемами, StarterKit и корневым ConvertKit 0.16.1. В обоих случаях документ сохранил явный `pageBreak`, браузер показал переход от одной страницы к двум. Это проверка конкретной композиции через PageKit. Прямой npm peer conflict Pages сохраняется, а разбиение таблиц и синхронизация конфигурации страниц в этом опыте остаются открытыми.

Конвертеры DOC, PDF, ODT и EPUB отправили в подставной `fetch` multipart с `docxFile`, начинающимся с ZIP-сигнатуры. В PDF без ExportDocx подтверждён JSON с вложенной строкой `doc`. Во всех пяти случаях callback получил Blob. Проверены сериализация клиента и точка завершения операции. Качество файлов удалённого конвертера требует отдельного прогона. [Runner](scripts/editor-utilities-probe.mjs) · [Результаты](results/editor-utilities.json)
