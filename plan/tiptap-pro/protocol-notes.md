# Протокол совместной работы: проверенные основы и программа экспериментов

Дата: 2026-11-21. Это описание публичных OSS-исходников и официального API, **не результат наблюдения нашего Cloud-окружения**. Фактические сетевые трассы и установленные версии пакетов фиксируются отдельно в лабораторном стенде.

Публичный Hocuspocus проверен на `main` commit `1293c71cd7d06567662cd8ea5bf75d20c09a8a8e`; `y-protocols/master` в момент проверки — `73b2ff75486c9879843718dc6c7fc52d63aea4fe`. Эти SHA не устанавливают версию hosted Tiptap Cloud. Версии клиента и сервера нельзя выводить из сходства интерфейсов.

## 1. Что передаётся между клиентом и сервером

У Hocuspocus есть собственная оболочка вокруг Yjs-протоколов. Документ адресуется в самом сообщении, а не только URL WebSocket:

```text
Hocuspocus frame := varString(documentName) · varUint(messageType) · payload
varString(s)     := varUint(byteLength(UTF8(s))) · UTF8(s)
varBuffer(b)     := varUint(byteLength(b)) · b
```

Это следует из исходников [SyncStepOneMessage](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/provider/src/OutgoingMessages/SyncStepOneMessage.ts) и серверного [OutgoingMessage](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/server/src/OutgoingMessage.ts). Нельзя применять decoder обычного `y-websocket` с чтения первого байта как opcode: сначала нужно снять адресную оболочку Hocuspocus.

В [provider MessageType](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/provider/src/types.ts) перечислены следующие номера:

| Тип | ID | Содержимое после внешней оболочки |
| --- | ---: | --- |
| Sync | 0 | Yjs sync subtype и бинарный payload |
| Awareness | 1 | `varBuffer(awarenessUpdate)` |
| Auth | 2 | auth subtype и зависящий от него payload |
| QueryAwareness | 3 | Запрос presence-состояния |
| Stateless | 5 | `varString(payload)` |
| CLOSE | 7 | При ответе сервера — строка причины |
| SyncStatus | 8 | Числовой признак применения update |
| Ping / Pong | 9 / 10 | Типы объявлены; поддержку и payload проверять по версии |

Наличие номера в enum не доказывает поддержку этого сообщения Cloud. Сервер также имеет внутренние типы `SyncReply=4` и `BroadcastStateless=6`; последний не является публичной клиентской командой. В [server MessageReceiver](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/server/src/MessageReceiver.ts) `BroadcastStateless` от WebSocket-клиента отвергается. В лаборатории достаточно штатных клиентских методов; внутренний opcode отправлять не требуется.

## 2. Yjs sync и значение подтверждений

Внутренние подтипы `Sync`: `0=SyncStep1`, `1=SyncStep2`, `2=Update`. Step1 содержит state vector, Step2 — недостающие бинарные изменения; дальнейшие правки передаются incremental update. Примитивы lib0 используют переменную длину целого и длину перед буферами/UTF-8. Формат документирован в [y-protocols PROTOCOL.md](https://github.com/yjs/y-protocols/blob/73b2ff75486c9879843718dc6c7fc52d63aea4fe/PROTOCOL.md).

```text
SyncStep1 frame = varString(room) · varUint(0) · varUint(0) · varBuffer(stateVector)
SyncStep2 frame = varString(room) · varUint(0) · varUint(1) · varBuffer(update)
Update frame    = varString(room) · varUint(0) · varUint(2) · varBuffer(update)
```

Бинарные Yjs updates применяются идемпотентно; их можно объединять и вычислять недостающую часть по state vector. Это позволяет повторную доставку и восстановление после reconnect. Но совпадение видимого текста недостаточно для проверки всех shared types: нужно отдельно сравнивать содержимое Tiptap, threads и другие контейнеры. Основа — [Yjs Document Updates](https://docs.yjs.dev/api/document-updates).

В [provider MessageReceiver](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/provider/src/MessageReceiver.ts) получение Step2 переводит provider в `synced=true`; положительный SyncStatus уменьшает счётчик `unsyncedChanges`. Это разные события. Из них нельзя заключать, что транзакция долговечно записана в SQL: нужен отдельный контракт persistence/receipt. В частности, OSS [server receiver](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/server/src/MessageReceiver.ts) отправляет SyncStatus после применения изменения к документу; условия записи определяются дополнительными hooks и storage. Для Vaulter сохраняем уже предусмотренное различие синхронизации и подтверждённого сохранения.

## 3. Авторизация и presence

Auth имеет подтипы `0=Token`, `1=PermissionDenied`, `2=Authenticated`; строка `readonly` или `read-write` приходит при успешном ответе. Запрос Token от сервера может потребовать повторной отправки токена. Источник — [common/auth.ts](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/common/src/auth.ts). Текущий [AuthenticationMessage](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/provider/src/OutgoingMessages/AuthenticationMessage.ts) помещает JWT в бинарное сообщение, после него — строку версии provider. Следовательно, скрыть только HTTP `Authorization` в отчёте недостаточно: raw WebSocket auth frame тоже содержит секрет.

Awareness — эфемерное состояние присутствия, отдельное от документа. Оно содержит client ID, clock и JSON-состояние; identity клиента нельзя считать авторитетной только потому, что она присутствует в awareness. Проверять нужно исчезновение presence после ухода, повторное появление при reconnect и отсутствие записи курсоров в пользовательское содержимое. Нормативное описание — [Awareness protocol](https://github.com/yjs/y-protocols/blob/73b2ff75486c9879843718dc6c7fc52d63aea4fe/PROTOCOL.md#4-awareness-protocol).

## 4. Stateless, версии, comments и tracked changes

Stateless — транспорт произвольной строки: [клиентский encoder](https://github.com/ueberdosis/hocuspocus/blob/1293c71cd7d06567662cd8ea5bf75d20c09a8a8e/packages/provider/src/OutgoingMessages/StatelessMessage.ts) не навязывает JSON-схему. Наблюдаемая строка должна сначала сохраняться как opaque payload, а JSON разбираться только при успешном parse. Не следует придумывать event names для ещё не выполненных операций.

Для snapshots официально описаны исходящие операции `document.revert`, `version.create`, `versions.list` и входящие события `version.created`, `versions.list`, `document.reverted`. Важная семантика: revert создаёт новую вершину истории; по умолчанию меняет `default` fragment, оставляя comments. Это контракт [Snapshots](https://tiptap.dev/docs/collaboration/documents/snapshot), но точные поля request-envelope и очередность нескольких ответов нужно зафиксировать capture установленного provider. JSON с таким event сам по себе не доказывает сохранение версии.

Для comments публичный API предоставляет `getThreads()` и `watchThreads()`/`unwatchThreads()`; callback watcher получает Yjs events и transaction. Это указывает на Yjs-реактивность thread-данных, но **не устанавливает точное имя shared type и серверную схему хранения**. Проверить `ydoc.share` и changes следует экспериментом; нельзя заранее объявлять, что каждая comment-команда — отдельный REST или stateless request. Источник — [Manage threads](https://tiptap.dev/docs/comments/core-concepts/manage-threads).

Tracked Changes поддерживает вставки, удаления, replacements, marks, attributes, split, sink и lift; предложения имеют автора и ID. Публичная документация обещает работу с Yjs — [Overview](https://tiptap.dev/docs/tracked-changes/getting-started/overview). Для анализа правок нужно снимать ProseMirror JSON до/после, а не только HTML: [Types](https://tiptap.dev/docs/tracked-changes/api-reference/types) различает `text` предложения и `fullText` вместе с вложенными предложениями других авторов. [Advanced usage](https://tiptap.dev/docs/tracked-changes/usage/advanced-usage) описывает группировку соседних правок одного автора, игнорирование remote sync при создании новых suggestions и ограничения node-type conversion с collaboration. Эти свойства должны стать отдельными сценариями собственной реализации.

## 5. Дизайн безопасного decoder для лаборатории

Сводный decoder реализован в [`scripts/decode-frame.mjs`](scripts/decode-frame.mjs). Его профиль соответствует описанному OSS envelope; совместимость с Cloud ещё проверяется экспериментом. `decodeFrame(bytes)` возвращает только сводку, `sanitize(value)` чистит вложенные наблюдения. Команды проверки:

```bash
node --test plan/tiptap-pro/scripts/decode-frame.test.mjs
node plan/tiptap-pro/scripts/decode-frame.mjs --self-check
node plan/tiptap-pro/scripts/decode-frame.mjs /path/to/local/binary-frame
```

Зависимость `lib0` берётся из изолированного `lab/node_modules`; перед запуском требуется установка зависимостей стенда. Правила decoder и перехвата:

1. Playwright сохраняет direction, monotonic timestamp, socket ID, длину frame и исходный тип данных. HTTP Upgrade фиксируется отдельно. Перехват подключается **до** создания provider.
2. Выбирается профиль установленной версии Hocuspocus; читаются `varString(documentName)` и `varUint(messageType)` с проверкой границ и максимальной длины. Ошибка даёт `decodeError`, не падение теста и не догадку по байтам.
3. Для Sync сохраняются subtype, размер update и безопасная сводка state vector. Опционально Yjs update применяется к отдельному shadow `Y.Doc`, который никогда не отправляет ответы. `Y.applyUpdate` с произвольным чужим вводом не нужен.
4. Для Auth сохраняются subtype и результат. JWT/token полностью удаляются до записи отчёта или вывода в консоль. Unknown auth payload также не выгружается «для удобства».
5. Для Awareness сохраняются client IDs и разрешённые поля тестовых пользователей; для Stateless — JSON event и разрешённые поля. Секреты и произвольные bearer strings не включаются.
6. SyncStatus обрабатывается версионным decoder: текущий сервер пишет `writeVarUint(0|1)`, текущий provider читает `readVarInt` и сравнивает с `1`; для этих двух значений результат совпадает. Остальные значения нельзя интерпретировать как bool по truthiness.
7. При trailing bytes сохраняется их длина и статус `unparsed`, а не молчаливый успех. WebSocket fragmentation уже снимается browser API; нельзя склеивать разные прикладные сообщения только по соседству в трассе.
8. Публичная сводка содержит размеры, порядок событий, hashes тестовых payloads и результаты assertions. Полные HAR, traces и frames остаются локальными ignored artifacts: в них могут быть JWT и содержимое документов.

## 6. Матрица воспроизводимых black-box сценариев

Каждый run использует уникальный документ `tiptap-pro-lab-<runID>-<case>`, два независимых browser context и разных тестовых пользователей. Перед тестом фиксируются npm shrinkwrap, доступные add-ons, разрешения JWT и точный provider. Наличие кнопки в UI не равно успешной операции.

| ID | Действие | Наблюдение и критерий |
| --- | --- | --- |
| P01 | Два клиента открывают пустую room | Auth outcome, Step1/Step2, awareness, одинаковый document JSON |
| P02 | Одновременные вставки в один paragraph | Оба вклада сохранены; оба JSON сходятся, новые updates прекращаются |
| P03 | B offline, обе стороны редактируют, B reconnect | Сходимость без потери текста; порядок sync/ack; обновление presence |
| P04 | Закрыть все клиенты; открыть новый context | Подтверждённый контент прочитан заново; это проверка reload, не crash-durability |
| P05 | Read-only JWT; попытка правки через команды | Редактирование не принято сервером; новый writer не видит запрещённую правку |
| P06 | Неверная room в scoped JWT, истёкший JWT | Причина auth отказа; нет initial document disclosure; корректный retry |
| P07 | Комментарий на диапазоне, reply, resolve, reopen | Threads и anchors у обоих клиентов; `ydoc.share` delta; HTTP/WS классификация |
| P08 | Другой автор удаляет/переносит текст с anchor | Предсказуемый orphan/empty thread; thread не теряется молча |
| P09 | Create/list/revert snapshot при двух клиентах | Stateless events, новая версия, converged content, судьба comments |
| P10 | Revert при offline-правках второго клиента | Зафиксировать merge/rebase/отказ, не объявлять заранее «откат победил» |
| P11 | Suggest insert/delete/replace, accept/reject из B | Поля JSON/marks, авторство, результат на A и после reload |
| P12 | Два автора правят pending suggestion | Nested/group semantics; отдельные IDs и корректные text/fullText |
| P13 | Bold, node attribute, paragraph split, list indent | Способ хранения, undo/redo, accept/reject отдельно для каждой операции |
| P14 | Клиент со старой/несовместимой schema | Ошибка/пауза вместо тихого удаления неизвестных Pro nodes/marks |

Проверка собственной замены должна сравнивать **поведение и гарантии**, а не точный бинарный вывод Yjs: client IDs и metadata делают байты недетерминированными. Assertions сходимости проверяют JSON и semantic state, а wire-проверки — допустимую последовательность типов сообщений.

## 7. Что остаётся неизвестным о hosted backend

Даже идеальный capture не раскрывает реальные SQL-таблицы, формат индексов, число replicas, границу durable commit, retention tombstones, алгоритм snapshot compaction и поведение при process crash. Наблюдение reload доказывает доступность данных после отключения клиентов, но не сохранность при отказе сервера. Внутренние SQL-транзакции нельзя вывести из HTTP `200`, provider `synced` или SyncStatus.

Для собственного Vaulter требуется отдельно определить persistence receipts, атомарность update+revision+outbox, ACL перед загрузкой документа и обновлением состояния, restore-generation и правила клиентов со старой schema. Tiptap Cloud здесь служит наблюдаемым эталоном UX/API; собственная реализация должна опираться на явные контракты из `plan/`, а не на недоказанные предположения об устройстве Cloud.
