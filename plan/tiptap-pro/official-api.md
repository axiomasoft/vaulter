# Tiptap Pro: публичные контракты и точки экспериментов

Дата проверки: **2026-11-21**. Источники — официальная документация Tiptap 3.x. Эта записка описывает документированные API; она **не подтверждает**, что конкретный Cloud environment уже проверен стендом. Захваченные ответы, версии установленных пакетов и результаты сценариев должны фиксироваться отдельно. Секретов здесь нет.

## Доступ и npm

Есть два независимых вида доступа: персональный npm token для скачивания Pro-пакетов и подписанные сервером JWT для сервисов. Private key из Authentication не заменяет npm token. Область private registry — `@tiptap-pro`; открытые `@tiptap/*` устанавливаются из обычного npm. В `.npmrc` можно оставить только ссылку на переменную:

```ini
@tiptap-pro:registry=https://registry.tiptap.dev/
//registry.tiptap.dev/:_authToken=${TIPTAP_PRO_TOKEN}
```

`npm install` должен получить `TIPTAP_PRO_TOKEN` в окружении процесса: npm сам не загружает корневой `.env`. Документация называет npm token персональным и бессрочным; доступность скачивания пакета и доступность Cloud-функции — разные проверки. [Pro extensions](https://tiptap.dev/docs/guides/pro-extensions).

## JWT и идентификаторы

Подпись: ES256, ECDSA P-256, PKCS#8 PEM. JWT содержит обязательные `iss` (**environment hash ID**), `aud` (`Documents`, `AI`, `Convert` или массив) и `exp`; полезны `iat` и `sub`. Permission имеет `action`, `resource` и необязательные `constraints`. `Documents:Write` включает чтение и комментирование; `Documents:Api:All` даёт отдельный административный REST-доступ. Точный resource ограничивает документ; wildcard `*` допускает фильтры `prefix`, `suffix`, `in`. Private key остаётся на сервере. [Authentication](https://tiptap.dev/docs/authentication).

Пример собственного формирователя ограниченного токена для лабораторного документа:

```js
import { SignJWT, importPKCS8 } from 'jose'

const key = await importPKCS8(process.env.TIPTAP_PRIVATE_KEY, 'ES256')
const token = await new SignJWT({
  permissions: [{ action: 'Documents:Write', resource: documentName }],
})
  .setProtectedHeader({ alg: 'ES256' })
  .setIssuer(process.env.TIPTAP_ENVIRONMENT_ID)
  .setAudience(['Documents'])
  .setSubject(userId)
  .setIssuedAt()
  .setExpirationTime('10m')
  .sign(key)
```

Это пример интеграционного кода, а не секрет или сохранённый токен. Не смешивать browser JWT с REST admin JWT.

## Provider: Cloud и Hocuspocus

Пакет — `@tiptap-pro/provider`, класс — `TiptapCollabProvider`. Его `appId` обозначает **Document server ID**, а не environment ID. Настройки включают `name`, `document: Y.Doc`, `token`, `user`; для on-prem применяется `baseUrl`. `token` допускает строку, функцию и Promise. `unsyncedChanges` и соответствующее событие показывают неподтверждённые изменения; это отдельный сигнал от самого открытия WebSocket. [Provider integration](https://tiptap.dev/docs/collaboration/provider/integration).

Минимальная сборка:

```js
import * as Y from 'yjs'
import { TiptapCollabProvider } from '@tiptap-pro/provider'

const document = new Y.Doc()
const provider = new TiptapCollabProvider({
  appId: documentServerId,
  name: documentName,
  document,
  token: () => fetchSessionToken(documentName, userId),
  user: userId,
})
```

Документированная базовая архитектура — Yjs CRDT поверх открытого Hocuspocus. Это не доказательство идентичности Cloud сервера обычному `@hocuspocus/server`: snapshots, threads, авторизация и служебные сообщения расширяют протокол. [Collaboration overview](https://tiptap.dev/docs/collaboration/getting-started/overview).

## Document REST

База: `https://<DOCUMENT_SERVER_ID>.collab.tiptap.cloud`. Заголовок — `Authorization: Bearer <jwt>` с `Documents:Api:All`; токен серверный. Основные маршруты:

| Метод | Путь | Назначение |
| --- | --- | --- |
| POST | `/api/documents/:name?format=json` | Создать из Tiptap JSON; успех 204, существующий документ 409 |
| GET | `/api/documents/:name?format=json` | Получить JSON |
| GET | `/api/documents/:name?format=yjs` | Получить binary Yjs update |
| GET | `/api/documents?take=100&skip=0` | Перечислить документы |
| PATCH, PUT | `/api/documents/:name` | Применить update к существующему документу |
| HEAD | `/api/documents/:name` | Проверить наличие |
| GET, POST | `/api/documents/:name/versions` | Список / создание версии |
| GET, PATCH | `/api/documents/:name/versions/:version` | Чтение / метаданные версии |
| POST | `/api/documents/:name/versions/:version/revertTo` | Восстановление |
| POST | `/api/documents/:name/stateless` | Broadcast служебного сообщения |

При чтении открытого документа возвращается состояние из памяти, закрытого — из БД. JSON по умолчанию экспортирует fragment `default`; binary содержит весь Y.Doc. Имена со слешем нужно URL-кодировать. [Document REST API](https://tiptap.dev/docs/collaboration/documents/rest-api).

## Comments: данные отдельно от привязки

Пакет — `@tiptap-pro/extension-comments`. `CommentsKit.configure({ provider })` включает необходимые плагины и типы. [Install Comments](https://tiptap.dev/docs/comments/getting-started/install).

`setThread({content,data,commentData})` создаёт обсуждение на выделении; `resolveThread`, `unresolveThread`, `createComment`, `updateComment`, `removeComment` управляют жизненным циклом. `removeThread({id,deleteThread:true})` удаляет также данные из Y.Doc. [Editor commands](https://tiptap.dev/docs/comments/integrate/editor-commands).

`subscribeToThreads({editor,provider,callback})` возвращает threads с актуальными `ranges: [{from,to,type}]`; `type` — `inline` или `block`, граница `to` исключительная. Один thread может иметь несколько диапазонов или `ranges: []`. Позиции временные: после совместной правки нужно пересчитывать их. Без editor подписка отдаёт provider records без диапазонов; provider также имеет `getThreads`, `watchThreads`, `unwatchThreads`. [Manage threads](https://tiptap.dev/docs/comments/core-concepts/manage-threads).

REST prefix — `/api/documents/:name/threads`; доступны создание, список, чтение, обновление и удаление thread, а вложенные `/comments` — для комментариев. **POST thread создаёт запись без anchor**. Для отображения нужен отдельный `inlineThread` mark в содержимом. Это важный двухэтапный контракт для фонового агента. [Comments REST API](https://tiptap.dev/docs/comments/integrate/rest-api).

Серверный `thread_authenticator=1` использует JWT `sub`, назначает автора и ограничивает правку/удаление владельцем. По умолчанию выключен; отсутствие UI кнопки не заменяет серверное правило. `commentonly_disallow_resolve_foreign_threads=1` ограничивает разрешение чужих обсуждений пользователем comment-only. В примерах этой страницы остался legacy Authorization; актуальный REST Bearer указан выше. [Thread authentication](https://tiptap.dev/docs/comments/core-concepts/thread-authentication).

Для событий исчезновения anchor и смены состояния документированы `onThreadAnchorRemoved`, `onThreadStateChanged`; `trackedChangesIntegration` по умолчанию включён и связывает обсуждения с suggestion lifecycle. [Comments configure](https://tiptap.dev/docs/comments/core-concepts/configure).

## History / Snapshot

Пакеты — `@tiptap-pro/extension-snapshot`, `@hocuspocus/transformer`, `yjs`. Конфигурация — `Snapshot.configure({provider,onUpdate})`. API: `saveVersion(name,force,meta)`, `fetchVersions()`, `revertToVersion(version,...)`; provider — `listVersions`, `watchVersions`, `createVersion`, `previewVersion`. Version record: `{version,date,name?,meta?}`.

Автоверсии выключены по умолчанию, при включении интервал 30 секунд и только при изменении. Восстановление создаёт новую голову, предварительно сохраняя незаверсионированные изменения; по умолчанию меняется fragment `default`, comments не откатываются. В документации перечислены служебные события `version.create`, `versions.list`, `document.revert`, входящие `version.created`, `document.reverted`. `__tiptap` в meta зарезервирован для trigger/author attribution. Для доверенной attribution нужен `user_mapping_authenticator` и совпадение provider.user с JWT.sub. [Snapshot](https://tiptap.dev/docs/collaboration/documents/snapshot).

## Сравнение: два различных продукта

`@tiptap-pro/extension-snapshot-compare` настраивается через `SnapshotCompare.configure({provider})`; provider.user необходим для attribution. `showDiff` временно показывает diff вместо текущего содержимого, сохраняя предыдущее представление. Это не обычная операция сохранения правок в документ. [Snapshot Compare](https://tiptap.dev/docs/collaboration/documents/snapshot-compare).

Новый `@tiptap-pro/compare` — отдельный paid add-on; умеет сравнение вне editor, клиентское или серверное. В редактор подключается `Compare`. Не считать установку первого пакета доказательством права и возможности использовать второй. [Compare overview](https://tiptap.dev/docs/compare/getting-started/overview).

У Compare документированы `compareVersions` для двух Yjs **v2** updates, `displayChangeset`, `displayChangesetAsTrackedChanges`; результат содержит `docA`, `docB`, changes. Сравнение истории, предложение изменения и применение принятой правки — разные операции. [Commands](https://tiptap.dev/docs/compare/api-reference/commands), [Types](https://tiptap.dev/docs/compare/api-reference/types).

## Tracked Changes

Пакет — `@tiptap-pro/extension-tracked-changes`; конфигурация — `TrackedChanges.configure({enabled:true,userId,userMetadata})`. Это отдельный add-on, доступный также в trial. [Install Tracked Changes](https://tiptap.dev/docs/tracked-changes/getting-started/install).

Документированы восемь видов: `add`, `delete`, `replace`, `markChange`, `attributeChange`, `split`, `sink`, `lift`. Поддерживаются непрерывная группировка, вложенные предложения других авторов, атомарные узлы, таблицы, связка comments и Yjs. Это заявленные возможности, не результаты лаборатории. [Overview](https://tiptap.dev/docs/tracked-changes/getting-started/overview).

Команды: `enableTrackedChanges`, `disableTrackedChanges`, `toggleTrackedChanges`, `setTrackedChangesUser`, `acceptSuggestion({id})`, `rejectSuggestion({id})`, `acceptSuggestionsInRange`, `rejectSuggestionsInRange`. Принятие вставки оставляет текст и снимает suggestion mark. Нужны отдельные сценарии изменения вложенных узлов, reject после remote edit и undo/redo. [Commands](https://tiptap.dev/docs/tracked-changes/api-reference/commands).

## Conversion

Форматы: import DOCX/Markdown; export DOCX/PDF/ODT/EPUB/Markdown. REST обрабатывает запрос синхронно. `ConvertKit` из `@tiptap-pro/extension-convert-kit` задаёт schema, понимающую дополнительные свойства DOCX. Один `StarterKit` не является эквивалентной схемой roundtrip. [Overview](https://tiptap.dev/docs/conversion/getting-started/overview), [End-to-end walkthrough](https://tiptap.dev/docs/conversion/getting-started/guides/end-to-end-walkthrough).

Пакеты: `extension-import-docx`, `extension-export-docx`, `extension-export-pdf`, `extension-export-odt`, `extension-export-epub`, `extension-export-markdown` в scope `@tiptap-pro`. **DOCX export работает на клиенте без JWT и серверных вызовов**. Остальные требуют aud Convert и format/direction permission, например `Convert:Export:Pdf`; импорт в хранимый документ дополнительно требует Documents:Write. Отсутствующая permission и недоступная подписке функция могут обе давать 403 с различными code. [Install Conversion](https://tiptap.dev/docs/conversion/getting-started/install).

## AI Toolkit: server и client

Server SDK — открытый `@tiptap/ai-toolkit`; схема редактора дополняется `ServerAiToolkit`, `getEditorContext(editor)` передаётся в каждый запрос. Сервис по умолчанию `https://api.tiptap.dev`. Начальная точка: `POST /v4/ai/toolkit/fetch-tools`, JSON `{editorContext,tools:{tiptapRead:true,tiptapEdit:true}}`, Bearer JWT с AI:Toolkit. Для редактирования Cloud документа нужны также Documents audience и Documents:Write. Скачивание открытого SDK не даёт доступ к платному сервису. [Install server AI Toolkit](https://tiptap.dev/docs/ai/ai-toolkit/install).

Клиентская разновидность — `@tiptap-pro/client-ai-toolkit`, exports `AiToolkit`, `getAiToolkit`; все `@tiptap/*` должны быть не ниже 3.12.0. Это отдельный вариант исполнения, не просто другой entrypoint серверного пакета. [Install client AI Toolkit](https://tiptap.dev/docs/ai/ai-toolkit/client/install).

Режим review может представлять ответ как suggestions или `trackedChanges`, с `trackedChangesOptions:{userId,userMetadata}`; `commentsOptions` связывает metadata операций с обсуждениями. [Review options](https://tiptap.dev/docs/ai/ai-toolkit/client/api-reference/review-options).

## Что проверять экспериментами

Следующая таблица — **предлагаемая программа собственных измерений**, а не утверждение о внутренних алгоритмах Tiptap.

| Сценарий | Сохраняемый факт | Что это решает для Vaulter |
| --- | --- | --- |
| Два пользователя, одна комната | WS frame types, initial sync, update, awareness, ack | Граница открытого Hocuspocus и Cloud-расширений |
| Reconnect после offline edit | state vector, повторная доставка, итоговый JSON | Сходимость, защита от потери и дублирования |
| Read/comment/write токены | успешные и отклонённые действия | Серверное enforcement независимо от UI |
| Inline/block thread, удаление текста | JSON mark/node + Y.Doc map до/после | Хранение привязки и обсуждения, orphan lifecycle |
| Thread owner и чужой пользователь | Отказ сервера, локальный rollback | Доверие к author metadata |
| Version create/list/preview/revert | Stateless JSON, binary snapshots, новый version ID | Снимки, список версий, восстановление головы |
| Diff форматирования и таблиц | Changeset и отображаемый документ | Структурное сравнение вместо text diff |
| Track add/delete/replace, accept/reject | Marks/nodes/meta, JSON после действия | Собственная модель предложений |
| Concurrent edit внутри suggestion | Новый suggestion ID и связки авторов | Вложенные предложения и rebase |
| AI fetch-tools и малая правка | Schema tools, request/result, editorContext | Формат операций фонового агента |
| DOCX export без сети | Результат и список запросов | Подтвердить действительно локальный путь |

По клиентскому bundle и wire нельзя установить внутреннюю SQL-схему, порядок дискового commit, топологию Cloud или гарантию durability. Такие выводы отдельно помечать гипотезой; для нашей реализации требуются свои контракты и fault-тесты. В репозиторий записывать API-описания, собственные fixtures и редактированные captures; не переносить bundle, private source, ключи или bearer tokens в спецификацию.
