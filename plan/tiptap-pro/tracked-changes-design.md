# Tracked Changes: контракт 0.14.3 и проект собственной реализации

Дата: 2026-11-21. Установленный пакет: `@tiptap-pro/extension-tracked-changes@0.14.3`. Документ использует публичные exports, поставляемые `dist/index.d.ts` и `docs/{api,contracts,node-type-changes}.md`, а также собственные синтетические эксперименты. Vendor implementation/source в этот документ не перенесены.

Локальные и публичные наблюдения:

- [`results/public-tracked-changes.json`](results/public-tracked-changes.json): публичный playground, 11 assertions / 16 шагов; версия bundle playground неизвестна.
- [`results/tracked-changes-probe.json`](results/tracked-changes-probe.json): **установленный 0.14.3**, 8 сценариев / **28 checks PASS**, Chrome через Playwright, собственные документы без Cloud и без чтения корневого `.env`.

Повторить второй опыт: `node plan/tiptap-pro/scripts/tracked-changes-probe.mjs`. Runner использует отдельный Vite на `127.0.0.1:5198`, собственный Vite cache и закрывает браузер/сервер в `finally`. `lab/src/main.mjs` он не меняет.

## 1. Точный публичный интерфейс

`TrackedChanges` — named export. Подключение `TrackedChanges.configure({ enabled, userId, userMetadata })` уже включает `SuggestionMark`, `SuggestionNodeAttributes` и paste support; повторная регистрация этих компонентов не нужна. Настройки: `enabled=false`, `userId='anonymous'`, `userMetadata=null`, `ignoredTrackingMarks=[]`, `nodeTypeChangeTypes=[]`, `blockSplitMarker='¶'`. Callbacks: `onSuggestionCreate(suggestion)`, `onSuggestionAccept(id)`, `onSuggestionReject(id)`.

Изменяемое состояние доступно через `editor.storage.trackedChanges`, но для переключения режима/автора предназначены команды. Публичные параметры команд проверены по declarations установленной версии:

| Команды | Параметры / назначение |
| --- | --- |
| `enableTrackedChanges()`, `disableTrackedChanges()`, `toggleTrackedChanges()` | Режим последующих правок |
| `setTrackedChangesUser(...)` | `{ userId, userMetadata? }` для последующих предложений |
| `acceptSuggestion(...)`, `rejectSuggestion(...)` | Необязательный `{ id? }`; без ID используется selection |
| `acceptAllSuggestions()`, `rejectAllSuggestions()` | Review всего документа |
| `acceptSuggestionsInRange(...)`, `rejectSuggestionsInRange(...)` | `{ from, to }`; обработка пересекающихся предложений |
| `acceptSuggestionsByUser(...)`, `rejectSuggestionsByUser(...)` | `{ userId }` |
| `addTrackedInsertion(...)` | `{ from, content, reason?, skipTrailingNode? }` |
| `addTrackedDeletion(...)` | `{ from, to, reason? }` |
| `addTrackedReplacement(...)` | `{ from, to, content, reason?, skipTrailingNode? }` |
| `addTrackedMark(...)`, `removeTrackedMark(...)`, `toggleTrackedMark(...)` | `{ from, to, markName, markAttrs?, reason? }` |
| `setSuggestionMark(...)` | Готовые mark attrs: `id`, `type`, `userId`, `createdAt`, `userMetadata`; необязательные `updatedAt`, `markChanges` |
| `unsetSuggestionMark()` | Снять mark, сохранив текст; это не эквивалент review для любого типа |

Команды возвращают boolean. Явные `addTracked*` создают предложения даже при выключенном обычном tracking. `content` — Tiptap Content, позиции — ProseMirror positions, а не индексы строки. `reason` относится к уведомлению создания и не хранится в document JSON; отсутствие persisted reason подтверждено probe. `skipTrailingNode` — transaction meta для взаимодействия с trailing-node behavior.

Query exports: `findSuggestions(editor, markName?, filters?)`, `findSuggestionsInDocument({doc,markName?,options?})`, `findSuggestionById`, `findSuggestionRanges`, `findSuggestionsInRange`, `getSuggestionAtPosition`, `getSuggestionAtSelection`, `getSuggestionRange`, `suggestionExists`. Фильтры `findSuggestions`: `type`, `userId`, `id`. Основной default mark name — `suggestion`. Результаты содержат текущие `from/to`; эти числа нужно запрашивать заново после изменения документа.

Проекции без изменения live editor:

- `withoutTrackedChanges({ content, schema })`: копия документа после отклонения всех pending proposals; tracking attrs удалены.
- `resolveTrackedChanges({ content, schema, accept })`: копия, где callback выбирает принимаемые предложения, остальные отклоняются.

Probe подтвердил отсутствие изменения исходного editor при обеих проекциях. Это подходящие API для baseline/export, но они не восстанавливают уже принятые или изначально untracked правки.

`TRACKED_CHANGES_EVENTS` содержит события с префиксом `trackedChanges:`: `suggestionCreated`, `suggestionAccepted`, `suggestionRejected`, `suggestionRemoved`, `suggestionsUpdated`, `suggestionRangeChanged`, `suggestionChanged`, `enabled`, `disabled`. Event payload может включать Transaction; в evidence сохранять полную транзакцию не требуется. Для sidebar достаточно query результата и changed IDs.

Runtime также экспортирует низкоуровневые `TrackedReplaceStep`, `TrackedReplaceAroundStep`, `TrackedStepBase`, helpers анализа транзакций и review transforms. Наличие этих exports не делает их необходимыми для нашей реализации; зависимость Vaulter от Pro helpers здесь не предлагается.

## 2. Persisted representation

### Текстовые marks

В наших JSON observations text nodes содержат mark `type='suggestion'`. Его attrs:

| Поле | Значение |
| --- | --- |
| `id` | Идентификатор логического предложения |
| `type` | `add`, `delete`, `replaceDeletion`, `replaceInsertion`, `markChange` |
| `userId` | Автор; значение задаётся приложением |
| `createdAt`, `updatedAt` | ISO timestamps; query имеет fallback на creation time для старых данных |
| `userMetadata` | JSON object или null |
| `markChanges` | Для formatting: records `{ operation: 'added'|'removed', markName, markAttrs }`; иначе null |

Общий query type отличается от persisted type: пара `replaceDeletion`/`replaceInsertion` представляется одним `Suggestion.type='replace'`. В публичном демо замена `ZZLAB_REPLACE_OLD → ZZLAB_REPLACE_NEW` сохраняла общий префикс обычным текстом и маркировала только `OLD`/`NEW`. Нельзя предполагать две полные копии исходного диапазона.

### Node attrs

Установленная extension добавляет nullable tracking attrs узлам, включая в наших snapshots root doc и paragraph: `suggestionId`, `suggestionType`, `suggestionUserId`, `suggestionCreatedAt`, `suggestionUpdatedAt`, `suggestionUserMetadata`, `suggestionNestingDelta`, `suggestionSplitSide`, `suggestionSplitKind`, `suggestionAttributeChanges`, `suggestionNodeTypeChange`.

Помимо текстовых видов node type допускает `attributeChange`, `sink`, `lift`, `blockSplit`. В query `blockSplit` нормализуется в `split`. Для attrs хранится список `{ attributeName, oldValue, newValue }`. У list nesting — signed delta. `suggestionNodeTypeChange` — независимое описание исходной формы wrapper, а не ещё один текстовый mark.

Probe custom atom `probeAtom` подтвердил `color='red'` в самом узле одновременно с `oldValue='blue'`, `newValue='red'` в предложении. Повторная правка тем же автором до green сохранила ID и original blue. Возврат к blue отменил предложение. Reject восстановил blue; accept оставил green.

DOM проекция использует `data-suggestion-*`: `id`, `type`, `user`, `created`, `updated`, `user-metadata`, `mark-changes`, `attribute-changes`, `nesting-delta`, `split-side`, `split-kind`, `node-type-change`, плюс атрибут присутствия `data-suggestion`. Для custom NodeViews существует `getSuggestionHTMLAttributes(node)`. HTML и JSON не следует считать взаимозаменяемыми, если renderer удаляет tracking attrs.

## 3. Проверенная семантика transforms

| Вид | Pending state | Accept | Reject | Доказательство |
| --- | --- | --- | --- | --- |
| Add | Новое содержимое под mark | Оставить текст, снять mark | Удалить добавленный текст | Public demo |
| Delete | Старое содержимое остаётся под mark | Удалить содержимое | Оставить, снять mark | Public demo |
| Replace | Связанные старый/новый фрагменты | Удалить старый, оставить новый | Оставить старый, удалить новый | Public demo |
| MarkChange: added bold | Bold уже присутствует и описан в proposal | Сохранить bold | Убрать bold | Installed probe |
| AttributeChange | Узел уже имеет предложенные attrs, original записан отдельно | Сохранить attrs | Вернуть original attrs | Installed probe |
| Structural split | Два блока, второй имеет `blockSplit/structural` | Сохранить границу | Склеить блоки | Installed probe |
| Insertion split | Новый блок после прежнего, `blockSplit/insertion` | Сохранить новый блок | Удалить новый блок | Reject — installed probe; accept — documented contract |

В `structural-paragraph-split` текст `alpha beta` разбит после `alpha`: reject вернул один paragraph с полным исходным текстом, accept оставил два. В `end-paragraph-split` Enter после `alpha` создал пустой блок `splitKind='insertion'`; reject удалил его. Такую разницу нельзя выразить одним правилом «при reject удалить второй paragraph»: это потеряет хвост исходного текста у structural split.

Прямые `setSuggestionMark/unsetSuggestionMark` управляют metadata, а не всей review-семантикой. Аналогично boolean success обычной PM-команды не гарантирует изменение document при transaction filter; UI должен учитывать фактическое состояние и события.

## 4. Grouping и несколько авторов

Проверено установленным probe:

1. Два последовательных `insertContent('x')`, `insertContent('y')` одного автора рядом дают одно предложение `text='xy'`.
2. Удаление `y` из собственной pending вставки уменьшает её до `x`; отдельного delete proposal не появляется.
3. Соседняя вставка другого автора не сливается в ID первого автора.
4. A предлагает `Hello world `; B вставляет `great ` внутрь. На текстовом узле B одновременно присутствуют два marks `suggestion` с разными IDs. Query A: `text='Hello world '`, `fullText='Hello great world '`; query B: `text='great '`.
5. Reject B сохраняет pending A; последующий accept A даёт чистый `Hello world base`.

Документированные дополнительные правила: принимать outer можно с сохранением nested pending suggestions; отклонение outer удаляет вложенные правки вместе с их носителем. Block-level nesting не объявлен поддержанным. Эти два порядка outer-review нашим probe пока не проверены. Источник — [Advanced usage](https://tiptap.dev/docs/tracked-changes/usage/advanced-usage).

Тот же installed probe соединил две независимые `Y.Doc` через прямой `Y.applyUpdate`, без provider/WebSocket. У B удалённая вставка A осталась единственным proposal автора A; новая nested вставка B сошлась на обоих editor JSON без дублирования proposals. Это доказывает tested local Yjs compatibility, но не доказывает устойчивость к offline concurrent review или работу hosted Cloud.

## 5. Ограничение node-type proposals

`nodeTypeChangeTypes=['paragraph','heading']` — отдельная opt-in возможность локального editor. В 0.14.3 её сочетание с Tiptap Collaboration/Yjs **выбрасывает исключение при инициализации**; probe проверил это поведение. Обычные paragraph splits при этом поддерживаются и не являются node-type proposals.

Поставляемый `docs/node-type-changes.md` отдельно описывает сохранение original wrapper/attrs и блокировку несовместимых structural edits. Для совместного Vaulter первый контракт должен оставить opt-in пустым и иметь явную политику для документов, пришедших с unsupported wrapper proposals. Одного совпадения JSON schema недостаточно для корректного конкурентного восстановления wrapper.

## 6. Где нужен backend

Обычные insert/delete/replace/review в публичном playground не создавали сетевых запросов. Installed probe выполнил formatting, attrs, splits и двухклиентскую Yjs-синхронизацию без сервера. Следовательно, отдельный hosted Tracked Changes backend не требуется для самих перечисленных клиентских transforms.

Backend всё равно нужен для продуктовых гарантий Vaulter:

- авторитетные actor/reviewer ACL и проверка прав перед загрузкой/записью;
- долговечное сохранение общего Yjs document и подтверждение сохранения;
- audit принятий/отклонений с actor и command ID;
- самостоятельное хранение reason/comments и их связи с proposal ID;
- generation/revision при restore и conflict policy при параллельном review;
- server-side export выбранной проекции для SDK и агента.

`userId` в mark задаётся клиентом, а сам probe успешно меняет его командой. Значит, содержимое marks не является доказательством авторства или разрешения принять чужую правку. В режиме suggest-only недостаточно передать клиенту обычное право записывать непрозрачные Yjs updates: доверенный сервер должен проверять допустимость перехода состояния либо сам исполнять команды review. Это проектное следствие, не выявленная уязвимость Cloud.

## 7. Независимый blueprint на Tiptap OSS / ProseMirror / Yjs

Ниже — собственный проект, а не восстановленный алгоритм vendor. Имена schema и API можно выбрать собственные; byte compatibility с Pro не является целью.

### A. Сначала определить модель и проекции

Ввести собственный `reviewSuggestion` mark с ID/author/timestamps/operation и возможность нескольких marks одного вида с разными IDs на одном text node. Block/atom state хранить в собственном versioned attr record. Разделить виды `insert`, `remove`, `replace`, `format`, `attributes`, `splitExisting`, `insertBlock`; не смешивать query terminology и storage encoding неявно.

Query строит logical proposals по ID, умеет несколько текущих ranges и отличает authored text от полного covered span. Абсолютные PM позиции не сохраняются как долговечные anchors. При внешних links/comments применять стабильные block IDs и/или Yjs RelativePosition с явной orphan policy; относительная позиция может не разрешиться после удаления. Основа — [Yjs RelativePosition](https://docs.yjs.dev/api/relative-positions).

Определить три чистых materialization функции: `baseline` (reject pending), `proposed` (accept pending), `review` (полное представление с metadata). Агент получает выбранную проекцию и opaque proposal IDs; в API нельзя молча трактовать `getText()` как окончательный документ.

### B. Нормализовать локальную транзакцию до публикации

На контролируемой границе dispatch классифицировать локальную транзакцию: текстовая вставка/удаление, замена, formatting, attrs, split/join, list movement. Строить итоговую review-транзакцию относительно исходного state, с корректной mapping всех последующих steps и selection. Сохранить native undo grouping и composition/IME lifecycle.

Нельзя выдавать `filterTransaction` за API замены транзакции: он возвращает boolean. `appendTransaction` добавляет последующую транзакцию и сам по себе не формулирует нашу гарантию «в Yjs не попала промежуточная untracked правка». Поэтому сначала выбрать и проверить конкретную dispatch-интеграцию, затем публиковать только нормализованный результат. Публичный контракт hooks: [ProseMirror plugin.ts](https://github.com/ProseMirror/prosemirror-state/blob/master/src/plugin.ts); назначение mapping: [transform/map.ts](https://github.com/ProseMirror/prosemirror-transform/blob/master/src/map.ts).

Удалённые Yjs updates, undo/redo и собственные review/normalization transactions должны иметь отдельный origin и не порождать новые proposals. Запретить рекурсивный re-tracking. При multi-step edit обязательно сохранять pre-state каждого шага; одного сравнения строк before/after недостаточно для marks, atom attrs и структурных преобразований.

### C. Сохранить обратимость каждого поддержанного вида

- Вставку обернуть новым или совместимым соседним proposal ID.
- Удаляемый baseline удержать под removal record; собственную pending вставку можно сократить без нового removal.
- Replacement связывать одним logical ID, сохраняя обе стороны и общие неизменные части отдельно.
- Formatting хранит точные mark name/attrs и направление операции; rejection выполняет inverse, не снимает все formatting marks подряд.
- Attribute proposal хранит earliest original value и latest proposed value; возврат к original отменяет только это изменение.
- Split existing сохраняет обратимую границу; insert block — новый блок. Reject выполняет разные transforms.
- Unsupported structural mutation отклонять атомарно, сохраняя документ и selection, а не частично исполнять.

Для accept/reject нужно заново найти текущие ID/ranges и построить один согласованный transform. Внутренний порядок обхода должен учитывать сдвиг позиций и вложенность; нельзя выполнять устаревшие from/to последовательно без mapping. Для nested review зафиксировать оба порядка действий и expected results до реализации.

### D. Отдельно задать авторитетность и сохранение

Client author context используется для UX. Сервер получает authenticated actor независимо от mark attrs. Для suggest-only и review ролей валидировать semantic transition на копии Y.Doc/PM state до применения к authoritative document либо принимать высокоуровневые review commands на сервере и генерировать canonical update там. Выбор требует отдельной спецификации latency и совместимости offline edits.

Review commit должен включать expected document generation, commandId, affected proposal IDs, actor и результат. Атомарно записывать принятый update, revision/receipt и audit/outbox в рамках существующего контракта `plan/`. Snapshot/restore не должен молча возвращать уже обработанные command IDs в pending состояние.

### E. Приёмка собственной реализации

Минимальный reusable behavior contract уже есть в двух runners: text review, grouping, formatting, attrs, split, nested authors и отсутствие remote re-tracking. Его следует перенести на собственные extension APIs с теми же semantic assertions, без зависимости от Pro schema names.

Оставшиеся обязательные сценарии: offline concurrent insert/review двумя авторами; reject outer до inner; accept outer при pending inner; paste sanitization и чужие IDs; IME/composition; drag/drop; lists/tables; code blocks; custom nodes; undo/redo; stale generation после restore; client без review schema. На этом этапе не заявляется полное соответствие Tracked Changes: нынешние evidence закрывают ровно перечисленные 28 checks.

## Проверка на Document Server

В двух отдельных браузерных контекстах Alice создала вставку, Bob её отклонил. Затем Alice предложила 15 → 30, Bob принял замену. Оба редактора показали `Payment in 30 days.` и пустой список предложений. После закрытия вкладок независимый REST readback вернул тот же текст без `suggestion` mark. Версия расширения в стенде 0.14.3. [Браузерный прогон](results/cloud-tracked-2026-11-21T09-25-34-839Z.json) · [REST readback](results/readback-tracked-changes-2026-11-21T09-27-43-374Z.json)
