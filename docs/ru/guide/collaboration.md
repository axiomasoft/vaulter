---
title: Совместное редактирование
description: Совместное редактирование документов в реальном времени на Yjs и внешнем хабе Hocuspocus — сессия, ограждение по поколению (epoch), загрузка и сидирование на сервере, отказы записи, read-only и обвязка @vaulter/vue.
outline: deep
---

# Совместное редактирование

Vaulter редактирует **узлы-документы** (`kind = document`, контент в агрегате `v_documents`)
совместно в реальном времени. Бесконфликтное слияние обеспечивает **Yjs** — бинарное
CRDT-состояние является **источником истины**, JSON TipTap — его проекция. Транспорт —
внешний хаб **Hocuspocus** (`hocuspocus-server/`) за контрактом
[`CollaborationDriver`](#расширение).

Reverb для совместного редактирования Yjs удалён: `vaulter-documents.collaboration.driver =
reverb` теперь даёт `InvalidConfigurationException`. Broadcast-каналы комментариев и их
проверки доступа сохраняются.

## Конфигурация

Все ключи находятся под `config('vaulter-documents.collaboration')`:

```php
// config/vaulter-documents.php
'collaboration' => [
    'enabled' => false,
    'driver' => 'hocuspocus',
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    'max_state_bytes' => 16777216,
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
```

| Ключ | Env | По умолчанию | Назначение |
|---|---|---|---|
| `enabled` | — | `false` | Opt-in. Выключено: маршруты не смонтированы, `collaborationSession()` отвечает `409 collaboration_unavailable`. |
| `driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | Единственный транспорт; `reverb` отвергается. |
| `broadcast.channel_prefix` | — | `vaulter.documents` | Пространство имён комнат: `<prefix>.<driveId>.<nodeId>.<epoch>`. |
| `hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | WebSocket-URL хаба для клиента. |
| `hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | *(не задан)* | Ключ HS256 для выпуска и проверки room-JWT. Только на стороне Laravel. |
| `hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | *(не задан)* | Ключ HMAC-подписи тела каждого вызова хаба. Общий с хабом; должен отличаться от `jwt_secret` — одинаковые значения дают `InvalidConfigurationException` при загрузке приложения в любом окружении (этот секрет есть у хаба, и при совпадении он смог бы подписать room-токен любого пользователя). |
| `hub.token_ttl` | — | `3600` | Срок жизни room-JWT в секундах, от 1 до 3600 (иначе `InvalidConfigurationException`). Права перепроверяются при каждом вызове хаба, поэтому токен не живёт дольше часа. |
| `hub.timestamp_tolerance` | — | `300` | Допустимый сдвиг часов подписанного вызова и окно защиты от повтора. |
| `max_state_bytes` | — | `16777216` | Максимальный бинарный Yjs-state одной записи (`413 document_state_too_large`). |

::: warning Коллаборация включается отдельно
Включите `vaulter-documents.collaboration.enabled`, установите `firebase/php-jwt` и задайте
оба секрета хаба — заданные и разные (одинаковые значения не дадут загрузиться). Кэш защиты
от повтора подписанных вызовов живёт в `Cache`-store приложения: в продакшне нужен общий для
всех инстансов store (Redis, database), а не `array` или `file`.
:::

## Поколения: epoch

У каждого документа есть **поколение коллаборации** (`v_documents.collab_epoch`). Оно растёт,
когда рабочее состояние заменяется не из комнаты:

- `restore()` ревизии;
- `update()` коллаборативного документа, у которого уже есть Yjs-state (замена JSON через
  API/PHP — Yjs-state сбрасывается, следующая комната сидируется из нового JSON).

Поколение входит в имя комнаты, токен и каждую запись. Это **storage fence**: запись несёт
epoch комнаты и сравнивается с `collab_epoch` на строке документа под `FOR UPDATE`; restore
меняет epoch под той же блокировкой. Поэтому запись комнаты, открытой до restore, либо
коммитится до него (и заменяется им), либо отвергается `409 collaboration_epoch_mismatch` —
как бы поздно ни сработал debounce хаба, она никогда не ляжет поверх восстановленного
документа. Отменить debounce нельзя; fence делает это ненужным.

Хаб не узнаёт о restore до следующей записи этой комнаты (не позже
`VAULTER_COLLAB_MAX_DEBOUNCE_MS` после следующей правки). Тогда он закрывает комнату кодом
**4409** `epoch_changed`; клиенты открывают новую сессию и **новый** `Y.Doc`.

## Поток подключения

```
Vue client ──POST /api/vaulter/v1/documents/{node}/collaboration/session {schema_version?}──▶ Laravel
            ◀──────────────── CollaborationSession (room, epoch, mode, token) ─┘
            │
            ▼
      ws to the hub, document name = room, token = room JWT
            │   onAuthenticate ──▶ POST v1/collaboration/hub/authenticate   (live mode)
            │   onLoadDocument ──▶ POST v1/collaboration/hub/load           (Yjs state, or JSON to seed once)
            │   onStoreDocument ─▶ POST v1/collaboration/hub/store          (behind the epoch fence)
```

1. Клиент вызывает `POST {prefix}/v1/documents/{node}/collaboration/session` (`201`), при желании
   передавая `schema_version` своего редактора. Маршрут зарегистрирован всегда; выключенную
   коллаборацию отклоняет операция.
2. `DocumentOperations::collaborationSession($document, $clientSchemaVersion)` находит узел,
   авторизует `View` (невидимый узел — `404 node_not_found`) и выбирает режим. Сессия
   **read-write**, только если выполнено всё:
   - у актора есть `Edit`;
   - тип документа установлен, а контент читается без потерь в схеме, которую эта установка
     пишет;
   - версия схемы клиента не задана или совпадает с версией типа;
   - диск активен (архивный — read-only).

   Выключенная коллаборация или тип без `SupportsCollaboration` — `409
   collaboration_unavailable`.
3. Драйвер выпускает [сессию](#дескриптор-сессии).

### Дескриптор сессии

```json
{
  "driver": "hocuspocus",
  "room": "vaulter.documents.01JDRIVE.01JNODE.3",
  "node_id": "01JNODE",
  "drive_id": "01JDRIVE",
  "epoch": 3,
  "schema_version": 1,
  "mode": "rw",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "url": "wss://collab.example.com"
}
```

Токен — JWT HS256 с claims `sub` (пользователь), `node`, `drive`, `tenant`, `epoch`,
`schema_version`, `mode` (`rw`|`ro`), `room`, `iat` и `exp`. Комната повторяет диск, узел и
epoch, поэтому токен привязан к одному поколению одного документа.

## Протокол хаба (сервер-к-серверу)

Эндпоинты хаба живут под `{prefix}/v1/collaboration/hub/` на собственном стеке middleware
(`routes.middleware.hub`, `throttle:vaulter-public`) и идут без auth-middleware Laravel — только
через `ResolveCollaborationCall`, который требует **оба** барьера. Они существуют, только пока
включён `vaulter-documents.collaboration.enabled`; иначе отвечают `404 route_not_found`:

- room-JWT в `Authorization: Bearer` (подпись и `exp`; комната разбирается и совпадает с
  claims `node`/`drive`/`epoch`);
- HMAC-SHA256 подпись `"{timestamp}.{rawBody}"` ключом `hub.webhook_secret` в
  `X-Vaulter-Collab-Timestamp` + `X-Vaulter-Collab-Signature`. Устаревшая метка времени и
  повтор подписи отвергаются. Хаб кладёт в каждое тело случайный `nonce`, поэтому два
  настоящих вызова с одинаковой нагрузкой никогда не делят подпись.

Иначе — `401 actor_required` в виде `application/problem+json`. Общий секрет сам по себе больше не
является личностью. Любой отказ на этих маршрутах — `401`, `403`, `409`, `413`, `422` — это
problem-тело со стабильным `code`; сам протокол не изменился.

Каждый вызов перечитывает живое состояние: узел существует, не в корзине, принадлежит диску
и тенанту токена, диск активен или в архиве, epoch комнаты текущий, права пользователя —
**на этот момент**. Отзыв `Edit` действует на следующем вызове хаба (мгновенное отключение
на каждом инстансе — позднейшая возможность).

| Эндпоинт | Хук хаба | Тело | Ответ |
|---|---|---|---|
| `POST v1/collaboration/hub/authenticate` | `onAuthenticate` | `{ room }` | `{ allowed, mode, userId, epoch }`; `allowed:false`, если пользователь потерял `View`; `409 collaboration_epoch_mismatch` для прошлого поколения. |
| `POST v1/collaboration/hub/load` | `onLoadDocument` | `{ room }` | `{ state, content, epoch, schemaVersion, stateVersion, type }` — `state` это base64 Yjs-state, либо `null` и JSON `content` для однократного сидирования. |
| `POST v1/collaboration/hub/store` | `onStoreDocument` | `{ room, state, content, seed?, state_version? }` | `{ changed, epoch, state_version, yjs_state_size }`. `state_version` отправляется только с seed (см. ниже). |

Правила `collaboration/hub/store`:

- read-only токен, пользователь без `Edit` сейчас, архивный диск или токен другой версии
  схемы — `403`;
- epoch комнаты — это fence: `409 collaboration_epoch_mismatch`;
- `seed: true` пишется, только пока у документа нет Yjs-state: иначе `409
  collaboration_seed_conflict`, поэтому два инстанса хаба никогда не сидируют оба;
- seed несёт также `state_version`, который вернул `collaboration/hub/load`, и пишется, только пока
  документ всё ещё на нём. REST `update()` документа **без** Yjs-state не двигает epoch, и без
  этого предусловия seed, построенный из JSON, прочитанного хабом ранее, затёр бы более
  новый текст. Сдвинувшаяся версия — тот же `409 collaboration_seed_conflict` (с
  `context.expected_state_version` / `actual_state_version`); хаб загружает документ заново и
  сидирует из актуального JSON. Некорректный `state_version` — `422`;
- `content` обязателен для типа, чей `projectFromState()` возвращает `null` (оба встроенных
  типа): хаб отправляет `TiptapTransformer.fromYdoc(document, 'default')`. Его разбирает кодек
  типа с лимитом размера (`422 invalid_document_content`);
- state больше `max_state_bytes` — `413 document_state_too_large`;
- контент, который эта установка не может сохранить обратно, сохраняет свои `409
  document_type_unavailable` / `document_content_degraded`, как и автосохранение.

### Когда бэкенд отказывает в записи

Хаб никогда не считает отказанную запись сохранённой. После повторов для сетевых ошибок,
`5xx` и `408`/`425`/`429` остаются ответы, которые повтор не изменит:

| Ответ | Смысл | Что делает хаб | Код закрытия (реакция клиента) |
|---|---|---|---|
| `409 collaboration_epoch_mismatch` / `collaboration_seed_conflict` | Поколение комнаты закончилось | Ограждает комнату, отбрасывает её следующие записи | **4409** `epoch_changed` — новая сессия, **новый** `Y.Doc` |
| `404` | Документ удалён | Ограждает комнату | **4404** `document_gone` |
| `401` | Токен последнего писателя истёк | Закрывает комнату, держит состояние в памяти, бросает исключение | **4001** `Collaboration token expired` — новая сессия, тот же `Y.Doc` |
| `403` | Последний писатель потерял `Edit` (или диск в архиве) | Закрывает комнату, держит состояние в памяти, бросает исключение | **4403** `forbidden` — новая сессия (теперь read-only), тот же `Y.Doc` |
| `413`, `422` | Состояние слишком велико / проекция не является валидным контентом | Логирует, закрывает комнату, держит состояние в памяти, бросает исключение | **4422** `store_refused` — клиент **не** переоткрывает; выставляется `error` |

«Держит состояние в памяти и бросает исключение» значит, что Hocuspocus не считает состояние
сохранённым: документ остаётся загруженным и сохраняется снова при следующем изменении или
когда подключается писатель с валидным токеном (клиент сохраняет свой `Y.Doc` и досинхронизирует
его). Комната, закрытая с `4001`/`4403`, не ограждается, поэтому следующая запись пробуется.

### Загрузка и сидирование — только на хабе

`onLoadDocument` хаба применяет сохранённый Yjs-state. Документ без него (новый или только что
восстановленный до ревизии без состояния) строится из JSON **один раз** на сервере через
`@hocuspocus/transformer` и схему TipTap хаба (`hocuspocus-server/src/schema.js`) и
сохраняется с `seed: true` до того, как его увидит любой клиент.

Seed строится fail-closed:

- **Ничего не обрезается молча.** Схема TipTap хаба знает только расширения из
  `hocuspocus-server/src/schema.js`; ProseMirror отбрасывает атрибуты, которых не объявляет.
  Документ, чей JSON схема не удержала бы целиком — обычно атрибут хоста,
  разрешённый `vaulter-documents.tiptap.extra_attributes` без соответствующего расширения
  хаба, — отклоняется (`seed refused` с именем `узел.атрибут`, но не значением), а не
  сидируется и сохраняется обратно без него. Добавьте расширение в хаб (см.
  [Хаб Hocuspocus](/ru/guide/hocuspocus-server)).
- **Первое read-only подключение ждёт.** С Redis (несколько инстансов хаба делят один `Y.Doc`
  комнаты) read-only подключение к документу, у которого ещё нет Yjs-state, отклоняется: хаб не
  должен кэшировать пустой документ, поверх которого первый писатель спроецирует сохранённый
  JSON. Наблюдатель повторяет попытку, когда писатель уже открыл документ. Без Redis наблюдатель
  видит собственную копию.
- **Изменившийся документ сидируется заново.** При `collaboration_seed_conflict` хаб
  загружает документ снова: состояние другого инстанса побеждает; иначе сидирует из актуального
  JSON (не более трёх попыток).

::: danger Никогда не сидируйте на клиенте
В коллаборативном режиме **не** вызывайте `setContent()` редактора и не вставляйте JSON в
`Y.Doc`: два клиента, конвертирующие один JSON, создают разные Yjs-вставки, и слияние
покажет контент дважды. `VaulterDocumentNode` поэтому передаёт редактору `content: null`.
:::

### Read-only

Read-only проверяется дважды: хаб помечает `ro`-соединение read-only (Hocuspocus отбрасывает
его изменения) и не пишет в read-only контексте, а бэкенд отвергает запись с `ro`-токеном.
После истечения токена хаб отвергает каждое сообщение и закрывает соединение кодом `4001`;
клиент запрашивает новую сессию. Провайдер, переподключающийся с уже истёкшим токеном (ноутбук
проспал дольше `token_ttl`), получает отказ на аутентификации с той же причиной
`Collaboration token expired`, поэтому клиент обновляет сессию, а не остаётся отключённым.

## Развёртывание `hocuspocus-server`

Контракт окружения, Docker и health-эндпоинты — на странице
[Хаб Hocuspocus](/ru/guide/hocuspocus-server). На стороне Laravel:

```dotenv
VAULTER_COLLAB_DRIVER=hocuspocus
VAULTER_COLLAB_URL=wss://collab.example.com
VAULTER_COLLAB_SECRET=<32+ символов случайных>     # ключ room-JWT, только Laravel
VAULTER_COLLAB_WEBHOOK_SECRET=<другой случайный>   # общий с хабом, подписывает вызовы
```

## Фронтенд (`@vaulter/vue`)

`useNodeCollaboration` получает сессию, открывает `@hocuspocus/provider` на свежем `Y.Doc` и
обрабатывает терминальные закрытия хаба:

- **4409 `epoch_changed`** — провайдер и старый `Y.Doc` уничтожаются, запрашивается новая
  сессия, и `doc` **заменяется** новым `Y.Doc`. Перепривяжите редактор; никогда не переносите
  содержимое старого документа.
- **4001** (токен истёк, в том числе как отказ аутентификации с истёкшим токеном) и
  **4403** `forbidden` (писатель потерял `Edit`; новая сессия приходит read-only) —
  запрашивается новая сессия; `Y.Doc` сохраняется, пока комната того же поколения.
- **4422** `store_refused` — бэкенд отказал самому состоянию документа (`413`/`422`), и новая
  сессия получила бы тот же отказ: ничего не переоткрывается, выставляется `error`, редактор
  остаётся отключённым.

```ts
import { watch } from 'vue'
import { useNodeCollaboration } from '@vaulter/vue'
import { Editor } from '@tiptap/vue-3'
import Collaboration from '@tiptap/extension-collaboration'

const collab = useNodeCollaboration(nodeId, { supportedSchemaVersion: 1 })
await collab.connect()

let editor = makeEditor()
// A restore replaced the room: rebuild the editor on the new document.
watch(collab.doc, () => { editor.destroy(); editor = makeEditor() })

function makeEditor() {
  return new Editor({
    editable: !collab.readOnly.value,
    extensions: [Collaboration.configure({ document: collab.doc.value, field: 'default' })],
  })
}

// on unmount
collab.disconnect()
```

| Член | Тип | Примечание |
|---|---|---|
| `doc` | `ShallowRef<Y.Doc>` | Документ для привязки; заменяется при `epoch_changed`. |
| `session` | `Ref<NodeCollaborationSession \| null>` | Текущая сессия (room, epoch, mode, версия схемы). |
| `connecting` / `connected` | `Ref<boolean>` | Рукопожатие в процессе / живое соединение провайдера. |
| `readOnly` | `Ref<boolean>` | `mode: 'ro'` или схема сессии новее `supportedSchemaVersion`. |
| `schemaOutdated` | `Ref<boolean>` | Документ в более новой схеме, чем поддерживает редактор — попросите пользователя обновить приложение. |
| `collaborators` | `Ref<Collaborator[]>` | Удалённые участники из awareness. |
| `error` | `Ref<unknown>` | Любая ошибка получения сессии (и `store_refused`). |
| `fetchSession()` / `connect()` / `disconnect()` | | Только сессия / сессия и провайдер / остановка (идемпотентна). |

### `VaulterDocumentNode`

Когда выбранный handler коллаборативный (`capabilities.collaborative === true`) и
коллаборация включена, `VaulterDocumentNode` открывает сессию через `useNodeCollaboration` и
передаёт редактору `collaborative: true`, `content: null`, `ydoc` (заменяется после restore —
перепривязывайте при изменении), присутствие и состояние соединения. Иначе используется путь
автосохранения.

```vue
<VaulterDocumentNode
  :node-id="nodeId"
  :component-map="componentMap"
  :supported-schema-version="1"
  @collaborating="(readonly) => { /* a session opened */ }"
  @schema-outdated="(version) => { /* ask the user to update the application */ }"
  @collaboration-failed="(error) => { /* the session could not be opened; the editor is read-only */ }"
/>
```

- `collaboration` (по умолчанию `true`) — `false` принудительно включает автосохранение.
- `collaboration-providers` — подмена `createHocuspocusProvider`.
- `supported-schema-version` — отправляется с запросом сессии; документ в более новой схеме
  открывается read-only и вызывает `schema-outdated`.
- На автосохранение компонент переходит **только** если запрос сессии ответил `409
  collaboration_unavailable` (коллаборация выключена или тип не умеет коллаборацию —
  документированный контракт). Любая другая ошибка (сеть, `429`, `5xx`, `403`) оставляет
  редактор **read-only** на загруженном контенте и вызывает `collaboration-failed`:
  автосохранение в документ с Yjs-state сдвинуло бы его epoch, сбросило state и выбросило бы
  остальных участников. Для повтора вызовите `load()` (он экспонируется); состояние отдаёт
  `sessionFailed`.

## Расширение

Транспорт — один шов: case в `CollaborationDriverType` и реализация `CollaborationDriver`.
`issueSession($node, $epoch, $schemaVersion, $writable, $actorId)` лишь кодирует решение,
которое принял `collaborationSession()`; сам он никогда не авторизует.

## Дальше

- [Хаб Hocuspocus](/ru/guide/hocuspocus-server)
- [Ошибки](/ru/guide/errors) — `collaboration_*` и `document_state_too_large`
- [ADR-0001 — Транспорт совместного редактирования](/adr/0001-collaboration-transport)
- [Авторизация](/ru/guide/abilities) — как `View` / `Comment` / `Edit` соотносятся с чтением и записью
